<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\CreateNewUserWithTeams;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\CreateTeam;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateTeamName;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Mail\TeamInvitation;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $verifier = Mockery::mock(UncompromisedVerifier::class);
    $verifier->shouldReceive('verify')->andReturnTrue();
    app()->instance(UncompromisedVerifier::class, $verifier);
});

it('registers into an owned company without joining the first existing company or leaking role context', function (): void {
    $owner = User::factory()->create();
    $existingTeam = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    Role::create(['name' => 'tenant', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId($existingTeam->id);
    $user = app(CreateNewUser::class)->create(['name' => 'New Accountant', 'email' => 'new@example.test', 'password' => 'Synthetic-Password-42!', 'password_confirmation' => 'Synthetic-Password-42!', 'role' => 'tenant']);

    expect(Hash::check('Synthetic-Password-42!', $user->password))->toBeTrue()
        ->and($user->currentTeam->user_id)->toBe($user->id)
        ->and($user->belongsToTeam($existingTeam))->toBeFalse()
        ->and(getPermissionsTeamId())->toBe($existingTeam->id);
    $this->assertDatabaseHas('model_has_roles', ['model_id' => $user->id, 'team_id' => $user->current_team_id]);
});

it('rolls registration back when an allowed role is not configured', function (): void {
    expect(fn () => app(CreateNewUser::class)->create(['name' => 'New Accountant', 'email' => 'new@example.test', 'password' => 'Synthetic-Password-42!', 'password_confirmation' => 'Synthetic-Password-42!', 'role' => 'tenant']))
        ->toThrow(Exception::class, 'Invalid role specified');
    $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    expect(Team::count())->toBe(0)->and(getPermissionsTeamId())->toBeNull();
});

it('rejects privileged caller-selected registration roles before creating a user', function (): void {
    expect(fn () => app(CreateNewUser::class)->create(['name' => 'New Accountant', 'email' => 'new@example.test', 'password' => 'Synthetic-Password-42!', 'password_confirmation' => 'Synthetic-Password-42!', 'role' => 'super_admin']))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
});

it('rejects a breached password through the configured verification contract', function (): void {
    $verifier = Mockery::mock(UncompromisedVerifier::class);
    $verifier->shouldReceive('verify')->andReturnFalse();
    app()->instance(UncompromisedVerifier::class, $verifier);
    expect(fn () => app(CreateNewUser::class)->create(['name' => 'New Accountant', 'email' => 'new@example.test', 'password' => 'Synthetic-Password-42!', 'password_confirmation' => 'Synthetic-Password-42!', 'role' => 'tenant']))->toThrow(ValidationException::class);
    $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
});

it('permits company access for owners and members while denying strangers and unrelated model IDs', function (): void {
    $owner = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $member = User::factory()->create();
    $stranger = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);
    foreach (['update', 'delete', 'addTeamMember', 'updateTeamMember', 'removeTeamMember'] as $ability) {
        expect($owner->can($ability, $team))->toBeTrue()
            ->and($member->can($ability, $team))->toBeFalse()
            ->and($stranger->can($ability, $team))->toBeFalse();
    }
    expect($owner->can('view', $team))->toBeTrue()
        ->and($member->can('view', $team))->toBeTrue()
        ->and($stranger->can('view', $team))->toBeFalse()
        ->and($owner->can('viewAny', Team::class))->toBeTrue()
        ->and($owner->can('create', Team::class))->toBeTrue()
        ->and($owner->canAccessTenant($team))->toBeTrue()
        ->and($member->canAccessTenant($team))->toBeTrue()
        ->and($stranger->canAccessTenant($team))->toBeFalse()
        ->and($owner->canAccessTenant($owner))->toBeFalse();
});

it('creates an isolated personal team through the alternate registration action', function (): void {
    $user = app(CreateNewUserWithTeams::class)->create(['name' => 'Preview Accountant', 'email' => 'preview@example.test', 'password' => 'Synthetic-Password-42!', 'password_confirmation' => 'Synthetic-Password-42!', 'terms' => '1']);
    expect($user->fresh()->currentTeam->user_id)->toBe($user->id)
        ->and($user->ownedTeams()->count())->toBe(1)
        ->and(Hash::check('Synthetic-Password-42!', $user->password))->toBeTrue();
});

it('reports registration storage failures without logging SQL bindings or passwords', function (string $failure, int $code, string $expectedMessage): void {
    $error = new QueryException('sqlite', 'insert into users values (?)', ['Synthetic-Password-42!'], new PDOException($failure, $code));
    DB::swap(Mockery::mock(DB::getFacadeRoot()));
    DB::shouldReceive('transaction')->once()->andThrow($error);
    Log::spy();
    expect(fn () => app(CreateNewUser::class)->create(['name' => 'New Accountant', 'email' => 'new@example.test', 'password' => 'Synthetic-Password-42!', 'password_confirmation' => 'Synthetic-Password-42!', 'role' => 'tenant']))
        ->toThrow(Exception::class, $expectedMessage);
    Log::shouldHaveReceived('error')->once()->with('Database error during user creation', ['code' => $code]);
})->with([
    'duplicate' => ['Duplicate entry', 23000, 'A user with this email already exists'],
    'access denied' => ['Access denied', 1045, 'Database access denied'],
    'connection unavailable' => ['Connection unavailable', 2002, 'Unable to connect to the database'],
    'other storage failure' => ['Storage unavailable', 1, 'A database error occurred'],
]);

it('rolls back unexpected registration errors and restores the permission context', function (): void {
    DB::swap(Mockery::mock(DB::getFacadeRoot()));
    DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('private storage detail'));
    expect(fn () => app(CreateNewUser::class)->create(['name' => 'New Accountant', 'email' => 'new@example.test', 'password' => 'Synthetic-Password-42!', 'password_confirmation' => 'Synthetic-Password-42!', 'role' => 'tenant']))
        ->toThrow(Exception::class, 'An unexpected error occurred');
    $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    expect(getPermissionsTeamId())->toBeNull();
});

it('resets a forgotten password and rejects mismatched confirmation', function (): void {
    $user = User::factory()->create(['password' => Hash::make('Old-Password-42!')]);
    $action = app(ResetUserPassword::class);
    expect(fn () => $action->reset($user, ['password' => 'New-Password-42!', 'password_confirmation' => 'different-password-42']))->toThrow(ValidationException::class);
    expect(Hash::check('Old-Password-42!', $user->fresh()->password))->toBeTrue();
    $action->reset($user, ['password' => 'New-Password-42!', 'password_confirmation' => 'New-Password-42!']);
    expect(Hash::check('New-Password-42!', $user->fresh()->password))->toBeTrue();
});

it('requires the current password before updating it', function (): void {
    $user = User::factory()->create(['password' => Hash::make('Old-Password-42!')]);
    $this->actingAs($user);
    $action = app(UpdateUserPassword::class);
    expect(fn () => $action->update($user, ['current_password' => 'wrong-password', 'password' => 'New-Password-42!', 'password_confirmation' => 'New-Password-42!']))->toThrow(ValidationException::class);
    expect(Hash::check('Old-Password-42!', $user->fresh()->password))->toBeTrue();
    $action->update($user, ['current_password' => 'Old-Password-42!', 'password' => 'New-Password-42!', 'password_confirmation' => 'New-Password-42!']);
    expect(Hash::check('New-Password-42!', $user->fresh()->password))->toBeTrue();
});

it('validates profile uniqueness while allowing the existing email', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'own@example.test']);
    User::factory()->create(['email' => 'other@example.test']);
    $action = app(UpdateUserProfileInformation::class);
    expect(fn () => $action->update($user, ['name' => 'Updated', 'email' => 'other@example.test']))->toThrow(ValidationException::class);
    $action->update($user, ['name' => 'Updated', 'email' => 'own@example.test']);
    expect($user->fresh()->name)->toBe('Updated')->and($user->fresh()->email)->toBe('own@example.test');
});

it('lets only the owner rename a company and rejects an empty company name', function (): void {
    $owner = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $stranger = User::factory()->create();
    $action = app(UpdateTeamName::class);
    expect(fn () => $action->update($stranger, $team, ['name' => 'Stolen company']))->toThrow(AuthorizationException::class);
    expect(fn () => $action->update($owner, $team, ['name' => '']))->toThrow(ValidationException::class);
    $action->update($owner, $team, ['name' => 'Northstar Synthetic Company']);
    expect($team->fresh()->name)->toBe('Northstar Synthetic Company');
});

it('validates a new team and selects it for its owner', function (): void {
    $owner = User::factory()->create();
    expect(fn () => app(CreateTeam::class)->create($owner, ['name' => '']))->toThrow(ValidationException::class);
    $team = app(CreateTeam::class)->create($owner, ['name' => 'Northstar Synthetic Company']);
    expect($team->user_id)->toBe($owner->id)->and($owner->fresh()->current_team_id)->toBe($team->id);
});

it('adds a known member once and rejects unknown addresses and stranger changes', function (): void {
    $owner = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $member = User::factory()->create();
    $action = app(AddTeamMember::class);
    expect(fn () => $action->add($member, $team, $member->email, 'editor'))->toThrow(AuthorizationException::class);
    expect(fn () => $action->add($owner, $team, 'unknown@example.test', 'editor'))->toThrow(ValidationException::class);
    $action->add($owner, $team, $member->email, 'editor');
    expect($team->fresh()->hasUser($member))->toBeTrue();
    expect(fn () => $action->add($owner, $team->fresh(), $member->email, 'editor'))->toThrow(ValidationException::class);
});

it('records one company invitation and rejects duplicate invitations or existing members', function (): void {
    Mail::fake();
    $owner = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $action = app(InviteTeamMember::class);
    $action->invite($owner, $team, 'invite@example.test', 'editor');
    $this->assertDatabaseHas('team_invitations', ['team_id' => $team->id, 'email' => 'invite@example.test', 'role' => 'editor']);
    Mail::assertSent(TeamInvitation::class);
    expect(fn () => $action->invite($owner, $team, 'invite@example.test', 'editor'))->toThrow(ValidationException::class);
    expect(fn () => $action->invite($owner, $team, $owner->email, 'editor'))->toThrow(ValidationException::class);
});

it('permits a member to leave but prevents removal by a stranger and removal of the owner', function (): void {
    $owner = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $member = User::factory()->create();
    $stranger = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);
    $action = app(RemoveTeamMember::class);
    expect(fn () => $action->remove($stranger, $team, $member))->toThrow(AuthorizationException::class);
    expect(fn () => $action->remove($owner, $team, $owner))->toThrow(ValidationException::class);
    $action->remove($member, $team, $member);
    expect($team->fresh()->hasUser($member))->toBeFalse();
});
