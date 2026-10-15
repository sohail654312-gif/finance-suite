<?php

declare(strict_types=1);

use App\Filament\App\Pages\AccountSetupWizard;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamManagementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('guides a newly registered team to setup before showing the app dashboard', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    app(TeamManagementService::class)->createPersonalTeamForUser($user);

    $this->actingAs($user)
        ->get('/app')
        ->assertRedirect('/app/account-setup');
});

it('keeps team setup credentials encrypted and hidden from the model cast', function (): void {
    $owner = User::factory()->create();
    $team = Team::forceCreate([
        'user_id' => $owner->id,
        'name' => 'Encrypted setup team',
        'personal_team' => true,
        'accounting_setup' => [
            'currency' => 'GBP',
            'integrations' => ['xero' => ['client_secret' => 'secret-value']],
        ],
    ]);

    expect($team->getRawOriginal('accounting_setup'))
        ->not->toContain('secret-value');
    expect($team->accounting_setup['integrations']['xero']['client_secret'])
        ->toBe('secret-value');
});

it('loads and saves owned company setup while keeping existing integration secrets write only', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $team->forceFill(['accounting_setup' => ['currency' => 'GBP', 'integrations' => ['xero' => ['client_id' => 'synthetic-client', 'client_secret' => 'synthetic-existing-secret']]], 'vonage_key' => 'synthetic-vonage-key', 'vonage_secret' => 'synthetic-vonage-secret'])->save();
    $this->actingAs($owner->fresh());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant(null);

    Livewire::test(AccountSetupWizard::class)
        ->assertOk()
        ->assertSet('configuredIntegrations', ['xero'])
        ->assertSet('data.integrations.xero.client_secret', null)
        ->assertDontSee('synthetic-existing-secret')
        ->fillForm(['business_name' => 'Northstar Synthetic Company', 'country' => 'GB', 'currency' => 'GBP', 'fiscal_year_start' => '04-01', 'timezone' => 'Europe/London', 'integrations.xero.client_id' => ' replacement-client ', 'integrations.xero.client_secret' => '', 'vonage_from' => 'Northstar'])
        ->call('save')
        ->assertHasNoFormErrors();

    $team->refresh();
    expect($team->name)->toBe('Northstar Synthetic Company')
        ->and($team->accounting_setup['fiscal_year_start'])->toBe('04-01')
        ->and($team->accounting_setup['integrations']['xero'])->toBe(['client_id' => 'replacement-client', 'client_secret' => 'synthetic-existing-secret'])
        ->and($team->vonage_key)->toBe('synthetic-vonage-key')
        ->and($team->accounting_setup_completed_at)->not->toBeNull()
        ->and($team->getRawOriginal('accounting_setup'))->not->toContain('synthetic-existing-secret');
});

it('stores only supported provider settings and replaces explicitly submitted messaging secrets', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $this->actingAs($owner->fresh());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant(null);
    Livewire::test(AccountSetupWizard::class)
        ->fillForm(['business_name' => 'Synthetic Company', 'country' => 'GB', 'currency' => 'GBP', 'fiscal_year_start' => '01-01', 'timezone' => 'UTC', 'integrations.wise.client_id' => 'synthetic-client', 'integrations.wise.client_secret' => 'synthetic-secret', 'vonage_key' => 'synthetic-key', 'vonage_secret' => 'synthetic-messaging-secret'])
        ->set('data.integrations.unsupported.secret', 'rejected-secret')
        ->set('data.integrations.wise.unsupported', 'rejected-field')
        ->call('save')
        ->assertHasNoFormErrors();
    $team->refresh();
    expect($team->accounting_setup['integrations'])->toBe(['wise' => ['client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret']])
        ->and($team->vonage_key)->toBe('synthetic-key')
        ->and($team->vonage_secret)->toBe('synthetic-messaging-secret')
        ->and($team->getRawOriginal('vonage_secret'))->not->toBe('synthetic-messaging-secret');
});

it('denies company setup to a member and to a stranger even when a foreign tenant is selected', function (string $actor): void {
    $owner = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $user = User::factory()->create();
    if ($actor === 'member') {
        $team->users()->attach($user, ['role' => 'editor']);
        $user->switchTeam($team);
    }
    $this->actingAs($user->fresh());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);
    Livewire::test(AccountSetupWizard::class)->assertForbidden();
    expect($team->fresh()->accounting_setup_completed_at)->toBeNull();
})->with(['member', 'stranger']);

it('denies company setup when no owned company is available', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant(null);
    Livewire::test(AccountSetupWizard::class)->assertForbidden();
});
