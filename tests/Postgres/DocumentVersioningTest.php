<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Services\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

it('stores evidence and sequential versions on PostgreSQL', function (): void {
    expect(app()->environment())->toBe('testing')
        ->and(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('finance_suite_test');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $storage = Storage::fake(config('documents.disk'));
    $documents = app(DocumentService::class);
    $document = $documents->attach(Invoice::factory()->create(), UploadedFile::fake()->create('original.pdf', 1));
    $revision = $documents->addVersion($document, UploadedFile::fake()->create('revision.pdf', 1));

    expect($document->versions()->orderBy('version_number')->pluck('version_number')->all())->toBe([1, 2])
        ->and($document->fresh()->currentVersion->is($revision))->toBeTrue();
    $storage->assertExists($document->versions()->pluck('path')->all());
});
