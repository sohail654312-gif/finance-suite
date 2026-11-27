<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\TeamManagementService;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AutomationPack\Actions\CreateAutomationRecipe;
use Liberu\Accounting\AutomationPack\Models\AutomationRecipe;
use Liberu\Accounting\Copilot\Actions\CreateCopilotRequest;
use Liberu\Accounting\Copilot\Models\CopilotRequest;

function aiAutomationOwnedUser(): User
{
    $user = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($user);

    return $user->refresh();
}

function aiAutomationMember(User $owner, string $role): User
{
    $member = User::factory()->create();
    $owner->currentTeam->users()->attach($member, ['role' => $role]);
    $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

    return $member->refresh();
}

function aiAutomationAuthenticate(User $user): void
{
    Sanctum::actingAs($user, [
        'accounting.copilot.read', 'accounting.copilot.write',
        'accounting.automation-pack.read', 'accounting.automation-pack.write',
    ]);
}

/** @return array{CopilotRequest, AutomationRecipe} */
function aiAutomationFixtures(User $owner): array
{
    return [
        app(CreateCopilotRequest::class)->handle([
            'team_id' => $owner->current_team_id,
            'actor_id' => $owner->id,
            'kind' => 'summary',
            'prompt' => 'Private copilot tenant fixture',
            'confirmation_key' => 'private-confirmation',
        ]),
        app(CreateAutomationRecipe::class)->handle([
            'team_id' => $owner->current_team_id,
            'name' => 'Private automation tenant fixture',
            'trigger' => 'manual',
            'action' => 'draft_reminder',
        ]),
    ];
}

it('allows owners and stamps the persisted selected team and actor', function (): void {
    $owner = aiAutomationOwnedUser();
    $stranger = aiAutomationOwnedUser();
    aiAutomationAuthenticate($owner);

    $this->postJson('/api/v1/accounting/copilot/requests', [
        'kind' => 'summary', 'prompt' => 'Owned request', 'confirmation_key' => 'owned-confirmation',
        'team_id' => $stranger->current_team_id, 'actor_id' => $stranger->id,
    ])->assertCreated()->assertJsonPath('data.team_id', $owner->current_team_id)
        ->assertJsonPath('data.actor_id', $owner->id);

    $this->postJson('/api/v1/accounting/automation/recipes', [
        'name' => 'Owned recipe', 'trigger' => 'manual', 'action' => 'draft_reminder',
        'team_id' => $stranger->current_team_id,
    ])->assertCreated()->assertJsonPath('data.team_id', $owner->current_team_id)
        ->assertJsonPath('data.status', 'draft');

    $record = CopilotRequest::query()->sole();
    $this->postJson("/api/v1/accounting/copilot/requests/{$record->id}/confirm", [
        'confirmation_key' => 'owned-confirmation',
    ])->assertOk()->assertJsonPath('data.status', 'confirmed');

    $recipe = AutomationRecipe::query()->sole();
    $this->postJson("/api/v1/accounting/automation/recipes/{$recipe->id}/simulate", [
        'payload' => ['invoice' => 'fixture'],
    ])->assertOk()->assertJsonPath('data.would_execute', false);
});

it('permits persisted authorized accounting members to create and confirm', function (string $role): void {
    $owner = aiAutomationOwnedUser();
    $member = aiAutomationMember($owner, $role);
    [$record] = aiAutomationFixtures($owner);
    aiAutomationAuthenticate($member);

    $this->postJson('/api/v1/accounting/copilot/requests', [
        'kind' => 'summary', 'prompt' => 'Accounting member', 'confirmation_key' => 'member-confirmation',
    ])->assertCreated()->assertJsonPath('data.actor_id', $member->id);
    $this->postJson('/api/v1/accounting/automation/recipes', [
        'name' => 'Accounting member recipe', 'trigger' => 'manual', 'action' => 'draft_reminder',
    ])->assertCreated();
    $this->postJson("/api/v1/accounting/copilot/requests/{$record->id}/confirm", [
        'confirmation_key' => 'private-confirmation',
    ])->assertOk()->assertJsonPath('data.status', 'confirmed');
})->with(['admin', 'accountant']);

it('scopes lists and rejects cross-tenant record IDs even with valid token abilities', function (): void {
    $owner = aiAutomationOwnedUser();
    [$record, $recipe] = aiAutomationFixtures($owner);
    aiAutomationAuthenticate(aiAutomationOwnedUser());

    $this->getJson('/api/v1/accounting/copilot/requests?team_id='.$owner->current_team_id)
        ->assertOk()->assertJsonCount(0, 'data')->assertDontSee($record->prompt);
    $this->getJson('/api/v1/accounting/automation/recipes?team_id='.$owner->current_team_id)
        ->assertOk()->assertJsonCount(0, 'data.data')->assertDontSee($recipe->name);
    $this->postJson("/api/v1/accounting/copilot/requests/{$record->id}/confirm", [
        'confirmation_key' => 'private-confirmation',
    ])->assertNotFound();
    $this->postJson("/api/v1/accounting/automation/recipes/{$recipe->id}/simulate")
        ->assertNotFound();

    expect($record->fresh()->status)->toBe('awaiting_confirmation')
        ->and(AutomationRecipe::query()->count())->toBe(1);
});

it('allows read-only membership to read and simulate while denying all mutations', function (string $role): void {
    $owner = aiAutomationOwnedUser();
    [$record, $recipe] = aiAutomationFixtures($owner);
    aiAutomationAuthenticate(aiAutomationMember($owner, $role));

    $this->getJson('/api/v1/accounting/copilot/requests')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/accounting/automation/recipes')->assertOk()->assertJsonCount(1, 'data.data');
    $this->postJson("/api/v1/accounting/automation/recipes/{$recipe->id}/simulate")
        ->assertOk()->assertJsonPath('data.would_execute', false);
    $this->postJson('/api/v1/accounting/copilot/requests', [
        'kind' => 'summary', 'prompt' => 'Unauthorized request', 'confirmation_key' => 'forbidden-confirmation',
    ])->assertForbidden();
    $this->postJson('/api/v1/accounting/automation/recipes', [
        'name' => 'Unauthorized recipe', 'trigger' => 'manual', 'action' => 'draft_reminder',
    ])->assertForbidden();
    $this->postJson("/api/v1/accounting/copilot/requests/{$record->id}/confirm", [
        'confirmation_key' => 'private-confirmation',
    ])->assertForbidden();

    expect(CopilotRequest::query()->count())->toBe(1)
        ->and(AutomationRecipe::query()->count())->toBe(1)
        ->and($record->fresh()->status)->toBe('awaiting_confirmation');
})->with(['viewer', 'unknown-role']);

it('rejects every endpoint after membership is revoked despite cached relationships', function (): void {
    $owner = aiAutomationOwnedUser();
    [$record, $recipe] = aiAutomationFixtures($owner);
    $member = aiAutomationMember($owner, 'accountant');
    $member->load(['currentTeam', 'teams', 'ownedTeams']);
    aiAutomationAuthenticate($member);
    $owner->currentTeam->users()->detach($member);

    $this->getJson('/api/v1/accounting/copilot/requests')->assertForbidden();
    $this->getJson('/api/v1/accounting/automation/recipes')->assertForbidden();
    $this->postJson('/api/v1/accounting/copilot/requests', [
        'kind' => 'summary', 'prompt' => 'Revoked request', 'confirmation_key' => 'revoked-confirmation',
    ])->assertForbidden();
    $this->postJson('/api/v1/accounting/automation/recipes', [
        'name' => 'Revoked recipe', 'trigger' => 'manual', 'action' => 'draft_reminder',
    ])->assertForbidden();
    $this->postJson("/api/v1/accounting/copilot/requests/{$record->id}/confirm", [
        'confirmation_key' => 'private-confirmation',
    ])->assertForbidden();
    $this->postJson("/api/v1/accounting/automation/recipes/{$recipe->id}/simulate")->assertForbidden();

    expect(CopilotRequest::query()->count())->toBe(1)
        ->and(AutomationRecipe::query()->count())->toBe(1)
        ->and($record->fresh()->status)->toBe('awaiting_confirmation');
});

it('rechecks a persisted role downgrade instead of trusting a cached accounting role', function (): void {
    $owner = aiAutomationOwnedUser();
    [$record] = aiAutomationFixtures($owner);
    $member = aiAutomationMember($owner, 'accountant');
    $member->load(['currentTeam', 'teams']);
    aiAutomationAuthenticate($member);
    $owner->currentTeam->users()->updateExistingPivot($member->id, ['role' => 'viewer']);

    $this->getJson('/api/v1/accounting/copilot/requests')->assertOk();
    $this->getJson('/api/v1/accounting/automation/recipes')->assertOk();
    $this->postJson('/api/v1/accounting/copilot/requests', [
        'kind' => 'summary', 'prompt' => 'Downgraded request', 'confirmation_key' => 'downgraded-confirmation',
    ])->assertForbidden();
    $this->postJson('/api/v1/accounting/automation/recipes', [
        'name' => 'Downgraded recipe', 'trigger' => 'manual', 'action' => 'draft_reminder',
    ])->assertForbidden();
    $this->postJson("/api/v1/accounting/copilot/requests/{$record->id}/confirm", [
        'confirmation_key' => 'private-confirmation',
    ])->assertForbidden();

    expect(CopilotRequest::query()->count())->toBe(1)
        ->and(AutomationRecipe::query()->count())->toBe(1)
        ->and($record->fresh()->status)->toBe('awaiting_confirmation');
});

it('uses the persisted selected team when the authenticated identity has stale team state', function (): void {
    $owner = aiAutomationOwnedUser();
    [$record, $recipe] = aiAutomationFixtures($owner);
    $otherOwner = aiAutomationOwnedUser();
    $member = aiAutomationMember($owner, 'accountant');
    $otherOwner->currentTeam->users()->attach($member, ['role' => 'accountant']);
    $member->load(['currentTeam', 'teams']);
    aiAutomationAuthenticate($member);
    User::query()->whereKey($member->id)->update(['current_team_id' => $otherOwner->current_team_id]);

    $this->getJson('/api/v1/accounting/copilot/requests')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/accounting/automation/recipes')->assertOk()->assertJsonCount(0, 'data.data');
    $this->postJson("/api/v1/accounting/copilot/requests/{$record->id}/confirm", [
        'confirmation_key' => 'private-confirmation',
    ])->assertNotFound();
    $this->postJson("/api/v1/accounting/automation/recipes/{$recipe->id}/simulate")->assertNotFound();
    $this->postJson('/api/v1/accounting/copilot/requests', [
        'kind' => 'summary', 'prompt' => 'New selected team', 'confirmation_key' => 'new-selection',
    ])->assertCreated()->assertJsonPath('data.team_id', $otherOwner->current_team_id);
    $this->postJson('/api/v1/accounting/automation/recipes', [
        'name' => 'New selected team', 'trigger' => 'manual', 'action' => 'draft_reminder',
    ])->assertCreated()->assertJsonPath('data.team_id', $otherOwner->current_team_id);
});

it('denies missing persisted team context despite an authenticated cached identity', function (): void {
    $owner = aiAutomationOwnedUser();
    $owner->load('currentTeam');
    aiAutomationAuthenticate($owner);
    User::query()->whereKey($owner->id)->update(['current_team_id' => null]);

    $this->getJson('/api/v1/accounting/copilot/requests')->assertForbidden();
    $this->getJson('/api/v1/accounting/automation/recipes')->assertForbidden();
});

it('continues to enforce Sanctum write abilities for an authorized owner', function (): void {
    $owner = aiAutomationOwnedUser();
    [$record] = aiAutomationFixtures($owner);
    Sanctum::actingAs($owner, ['accounting.copilot.read', 'accounting.automation-pack.read']);

    $this->getJson('/api/v1/accounting/copilot/requests')->assertOk();
    $this->getJson('/api/v1/accounting/automation/recipes')->assertOk();
    $this->postJson('/api/v1/accounting/copilot/requests', [
        'kind' => 'summary', 'prompt' => 'Missing scope', 'confirmation_key' => 'missing-scope',
    ])->assertForbidden();
    $this->postJson('/api/v1/accounting/automation/recipes', [
        'name' => 'Missing scope', 'trigger' => 'manual', 'action' => 'draft_reminder',
    ])->assertForbidden();
    $this->postJson("/api/v1/accounting/copilot/requests/{$record->id}/confirm", [
        'confirmation_key' => 'private-confirmation',
    ])->assertForbidden();
});
