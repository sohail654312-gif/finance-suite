<?php

declare(strict_types=1);

use App\Filament\App\Resources\Invoices\Pages\EditInvoice;
use App\Filament\App\Resources\Invoices\RelationManagers\DocumentsRelationManager;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\TeamManagementService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('versions and downloads invoice evidence within the selected team', function (): void {
    $teams = app(TeamManagementService::class);
    $owner = User::factory()->create();
    $team = $teams->createPersonalTeamForUser($owner);
    $foreign = $teams->createPersonalTeamForUser(User::factory()->create());
    $invoice = Invoice::factory()->create(['team_id' => $team->getKey()]);
    $foreignInvoice = Invoice::factory()->create(['team_id' => $foreign->getKey()]);
    $disk = Storage::fake(config('documents.disk'));
    $documents = app(DocumentService::class);
    $attachment = $documents->attach($invoice, UploadedFile::fake()->create('evidence.pdf', 1));
    $foreignAttachment = $documents->attach($foreignInvoice, UploadedFile::fake()->create('foreign.pdf', 1));

    $this->actingAs($owner->fresh());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);
    expect(DocumentsRelationManager::canViewForRecord($invoice, EditInvoice::class))->toBeTrue()
        ->and(DocumentsRelationManager::canViewForRecord($foreignInvoice, EditInvoice::class))->toBeFalse();

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class])
        ->assertCanSeeTableRecords([$attachment])
        ->assertCanNotSeeTableRecords([$foreignAttachment])
        ->callAction(TestAction::make('uploadVersion')->table($attachment), data: [
            'file' => UploadedFile::fake()->create('revised.pdf', 2),
        ])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('download')->table($attachment))
        ->assertFileDownloaded('evidence.pdf');

    expect($attachment->fresh()->currentVersion->version_number)->toBe(2)
        ->and($foreignAttachment->fresh()->currentVersion->version_number)->toBe(1);
    $disk->assertExists($attachment->fresh()->currentVersion->path);
});
