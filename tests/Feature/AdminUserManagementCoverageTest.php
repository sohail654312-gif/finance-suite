<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamManagementService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Livewire\Livewire;

/** @return array{User,Team,User,Role} */
function adminUserCoverageFixture(): array
{
    $admin = User::factory()->create(['name' => 'Synthetic Finance Administrator']);
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($admin);
    setPermissionsTeamId($team->getKey());
    $adminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => $team->getKey()]);
    $auditorRole = Role::firstOrCreate(['name' => 'auditor', 'guard_name' => 'web', 'team_id' => $team->getKey()]);
    $admin->assignRole($adminRole);
    $target = User::factory()->create(['name' => 'Synthetic Accounting Reviewer', 'email' => 'reviewer@example.test']);
    $team->users()->attach($target, ['role' => 'editor']);
    $target->forceFill(['current_team_id' => $team->getKey()])->save();
    test()->actingAs($admin->fresh());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    return [$admin, $team, $target, $auditorRole];
}

it('renders the host administrator user table and applies actual verification search and age filters', function (): void {
    [, $team, $verified, $auditorRole] = adminUserCoverageFixture();
    $verified->assignRole($auditorRole);
    $unverified = User::factory()->unverified()->create(['name' => 'Unverified Finance Member']);
    $old = User::factory()->create(['name' => 'Archived Finance Member', 'created_at' => now()->subDays(60)]);
    $team->users()->attach($unverified, ['role' => 'editor']);
    $team->users()->attach($old, ['role' => 'editor']);

    Livewire::test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$verified, $unverified, $old])
        ->searchTable('Accounting Reviewer')
        ->assertCanSeeTableRecords([$verified])
        ->assertCanNotSeeTableRecords([$unverified, $old]);

    Livewire::test(ListUsers::class)
        ->filterTable('verified')
        ->assertCanSeeTableRecords([$verified, $old])
        ->assertCanNotSeeTableRecords([$unverified]);

    Livewire::test(ListUsers::class)
        ->filterTable('unverified')
        ->assertCanSeeTableRecords([$unverified])
        ->assertCanNotSeeTableRecords([$verified, $old]);

    Livewire::test(ListUsers::class)
        ->filterTable('created_at')
        ->assertCanSeeTableRecords([$verified, $unverified])
        ->assertCanNotSeeTableRecords([$old]);
});

it('renders the host profile infolist with persisted roles teams and MFA status without secrets', function (bool $verified): void {
    [, , $target, $auditorRole] = adminUserCoverageFixture();
    $target->assignRole($auditorRole);
    $target->forceFill([
        'email_verified_at' => $verified ? now() : null,
        'two_factor_confirmed_at' => $verified ? now() : null,
        'two_factor_secret' => 'synthetic-secret-must-not-render',
        'two_factor_recovery_codes' => 'synthetic-recovery-must-not-render',
    ])->save();

    Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
        ->assertOk()
        ->assertSee('Synthetic Accounting Reviewer')
        ->assertSee('reviewer@example.test')
        ->assertSee('User Profile')
        ->assertSee('Roles & Permissions')
        ->assertSee('Team Information')
        ->assertSee('Auditor')
        ->assertDontSee('synthetic-secret-must-not-render')
        ->assertDontSee('synthetic-recovery-must-not-render');
})->with([false, true]);

it('edits a user and real role membership while preserving the password when the field is blank', function (): void {
    [, , $target, $auditorRole] = adminUserCoverageFixture();
    $passwordBefore = $target->password;

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm([
            'name' => 'Updated Finance Reviewer', 'email' => 'updated-reviewer@example.test',
            'password' => '', 'roles' => [$auditorRole->getKey()],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $updated = $target->fresh();
    expect($updated->name)->toBe('Updated Finance Reviewer')
        ->and($updated->email)->toBe('updated-reviewer@example.test')
        ->and($updated->password)->toBe($passwordBefore)
        ->and($updated->hasRole('auditor'))->toBeTrue()
        ->and($updated->hasRole('super_admin'))->toBeFalse();
});

it('rotates an administrator-managed password using a hash instead of storing plaintext', function (): void {
    [, , $target] = adminUserCoverageFixture();
    $passwordBefore = $target->password;

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->set('data.password', 'Synthetic-Rotation-836!')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->password)->not->toBe($passwordBefore)
        ->not->toBe('Synthetic-Rotation-836!')
        ->and(Hash::check('Synthetic-Rotation-836!', $target->fresh()->password))->toBeTrue();
});

it('rejects duplicate email and invalid profile input without modifying the account', function (array $input, string $field): void {
    [$admin, , $target] = adminUserCoverageFixture();
    $before = $target->only(['name', 'email', 'password']);
    if ($field === 'email' && $input['email'] === 'duplicate') {
        $input['email'] = $admin->email;
    }

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm($input)
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect($target->fresh()->only(['name', 'email', 'password']))->toBe($before);
})->with([
    'duplicate email' => [['email' => 'duplicate'], 'email'],
    'malformed email' => [['email' => 'not-an-email'], 'email'],
    'missing name' => [['name' => ''], 'name'],
    'overlong name' => [['name' => str_repeat('x', 256)], 'name'],
]);

it('creates a tenant-associated user with hashed credentials and persisted selected roles', function (): void {
    [, $team, , $auditorRole] = adminUserCoverageFixture();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New Accounting Reviewer', 'email' => 'new-reviewer@example.test',
            'password' => 'Synthetic-New-836!', 'roles' => [$auditorRole->getKey()],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::where('email', 'new-reviewer@example.test')->firstOrFail();
    expect(Hash::check('Synthetic-New-836!', $created->password))->toBeTrue()
        ->and($created->password)->not->toBe('Synthetic-New-836!')
        ->and($created->hasRole('auditor'))->toBeTrue()
        ->and($created->hasRole('super_admin'))->toBeFalse()
        ->and($team->users()->whereKey($created->getKey())->exists())->toBeTrue();
});

it('denies the host administration resource and real admin HTTP routes to ordinary team members', function (): void {
    [, $team, $member] = adminUserCoverageFixture();
    $this->actingAs($member->fresh());
    expect(UserResource::canAccess())->toBeFalse();

    $this->get(UserResource::getUrl('edit', ['record' => $member->getRouteKey()], panel: 'admin', tenant: $team))
        ->assertForbidden();
});
