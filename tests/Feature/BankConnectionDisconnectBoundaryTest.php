<?php

declare(strict_types=1);

use App\Filament\App\Resources\BankConnections\Pages\ListBankConnections;
use App\Filament\App\Resources\BankConnections\Pages\ViewBankConnection;
use App\Models\BankConnection;
use App\Models\User;
use App\Services\PlaidService;
use App\Services\TeamManagementService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('disconnects an owned bank only after confirmation and does not touch another tenant', function (): void {
    $owner = User::factory()->create();
    $teams = app(TeamManagementService::class);
    $tenant = $teams->createPersonalTeamForUser($owner);
    $foreignOwner = User::factory()->create();
    $foreignTenant = $teams->createPersonalTeamForUser($foreignOwner);

    $owned = BankConnection::factory()->create([
        'user_id' => $owner->id,
        'team_id' => $tenant->id,
        'bank_id' => 'plaid',
        'status' => 'active',
        'plaid_item_id' => 'owned-item',
        'plaid_institution_id' => 'owned-institution',
        'plaid_access_token' => 'synthetic-owned-token',
        'plaid_cursor' => 'owned-cursor',
    ]);
    $foreign = BankConnection::factory()->create([
        'user_id' => $foreignOwner->id,
        'team_id' => $foreignTenant->id,
        'bank_id' => 'plaid',
        'status' => 'active',
        'plaid_item_id' => 'foreign-item',
        'plaid_access_token' => 'synthetic-foreign-token',
    ]);
    $provider = Mockery::mock(PlaidService::class);
    $provider->shouldReceive('removeItem')->once()->with('synthetic-owned-token')->andReturn(true);
    app()->instance(PlaidService::class, $provider);

    $this->actingAs($owner->fresh());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($tenant);
    Http::preventStrayRequests();

    Livewire::test(ListBankConnections::class)
        ->assertCanSeeTableRecords([$owned])
        ->assertCanNotSeeTableRecords([$foreign])
        ->callAction(TestAction::make('disconnect')->table($owned))
        ->assertHasNoActionErrors();

    $owned->refresh();
    $foreign->refresh();
    expect($owned->status)->toBe('inactive')
        ->and($owned->plaid_item_id)->toBeNull()
        ->and($owned->plaid_institution_id)->toBeNull()
        ->and($owned->plaid_access_token)->toBeNull()
        ->and($owned->plaid_cursor)->toBeNull()
        ->and($foreign->status)->toBe('active')
        ->and($foreign->plaid_access_token)->toBe('synthetic-foreign-token');
    Http::assertNothingSent();
});

it('preserves bank authorization and credentials when provider does not confirm removal', function (): void {
    $owner = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $connection = BankConnection::factory()->create([
        'user_id' => $owner->id,
        'team_id' => $team->id,
        'bank_id' => 'plaid',
        'status' => 'active',
        'plaid_item_id' => 'item-retained',
        'plaid_institution_id' => 'institution-retained',
        'plaid_access_token' => 'synthetic-retained-token',
        'plaid_cursor' => 'retained-cursor',
    ]);
    $provider = Mockery::mock(PlaidService::class);
    $provider->shouldReceive('removeItem')->once()->with('synthetic-retained-token')->andReturn(false);
    app()->instance(PlaidService::class, $provider);

    $this->actingAs($owner->fresh());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);
    Http::preventStrayRequests();

    Livewire::test(ListBankConnections::class)
        ->callAction(TestAction::make('disconnect')->table($connection))
        ->assertHasNoActionErrors();

    $connection->refresh();
    expect($connection->status)->toBe('active')
        ->and($connection->plaid_item_id)->toBe('item-retained')
        ->and($connection->plaid_institution_id)->toBe('institution-retained')
        ->and($connection->plaid_access_token)->toBe('synthetic-retained-token')
        ->and($connection->plaid_cursor)->toBe('retained-cursor');
    Http::assertNothingSent();
});

it('rejects a forged record id when a bank connection belongs to a different tenant', function (): void {
    $owner = User::factory()->create();
    $teams = app(TeamManagementService::class);
    $team = $teams->createPersonalTeamForUser($owner);
    $foreignOwner = User::factory()->create();
    $foreignTeam = $teams->createPersonalTeamForUser($foreignOwner);
    $foreignConnection = BankConnection::factory()->create([
        'user_id' => $foreignOwner->id,
        'team_id' => $foreignTeam->id,
        'bank_id' => 'plaid',
        'status' => 'active',
        'plaid_item_id' => 'foreign-only-item',
        'plaid_access_token' => 'synthetic-other-tenant-token',
    ]);

    $this->actingAs($owner->fresh());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);
    Http::preventStrayRequests();

    Livewire::test(ViewBankConnection::class, ['record' => $foreignConnection->getRouteKey()])
        ->assertNotFound();

    expect($foreignConnection->fresh()->plaid_item_id)->toBe('foreign-only-item')
        ->and($foreignConnection->fresh()->plaid_access_token)->toBe('synthetic-other-tenant-token');
    Http::assertNothingSent();
});
