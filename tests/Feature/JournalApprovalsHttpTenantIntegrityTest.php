<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\JournalApprovals\Actions\SubmitJournal;
use Liberu\Accounting\JournalApprovals\Models\ApprovalThreshold;
use Liberu\Accounting\JournalApprovals\Models\JournalApproval;

uses(RefreshDatabase::class);

function approvalHttpOwnedUser(): User
{
    $user = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($user);

    return $user->refresh();
}

function approvalHttpFixture(User $preparer): JournalApproval
{
    return app(SubmitJournal::class)->handle([
        'team_id' => $preparer->current_team_id,
        'approval_ref' => 'APR-PRIVATE-'.(string) $preparer->getKey(),
        'journal_type' => 'general',
        'journal_source' => 'accounting-general-ledger',
        'journal_ref' => 'J-PRIVATE',
        'preparer_ref' => (string) $preparer->getKey(),
        'currency' => 'GBP',
        'amount' => '100.00',
    ]);
}

it('prevents other tenants from reading or altering approvals', function (): void {
    $owner = approvalHttpOwnedUser();
    $stranger = approvalHttpOwnedUser();
    $approval = approvalHttpFixture($owner);

    Sanctum::actingAs($stranger, ['accounting.journal-approvals.read', 'accounting.journal-approvals.write']);

    $this->getJson('/api/v1/accounting/journal-approvals/')
        ->assertOk()
        ->assertDontSee($approval->approval_ref);
    $this->getJson("/api/v1/accounting/journal-approvals/{$approval->id}")
        ->assertForbidden();
    $this->postJson("/api/v1/accounting/journal-approvals/{$approval->id}/decide", ['approved' => true])
        ->assertForbidden();
    $this->postJson("/api/v1/accounting/journal-approvals/{$approval->id}/evidence", ['kind' => 'invoice'])
        ->assertForbidden();
    $this->postJson("/api/v1/accounting/journal-approvals/{$approval->id}/post", ['emergency' => true, 'reason' => 'Forged'])
        ->assertForbidden();
    $this->getJson('/api/v1/accounting/journal-approvals/?team_id='.$owner->current_team_id)
        ->assertForbidden();

    expect($approval->fresh()->decisions()->count())->toBe(0)
        ->and($approval->fresh()->evidence()->count())->toBe(0);
});

it('stamps the server-side team and preparer instead of trusting request fields', function (): void {
    $owner = approvalHttpOwnedUser();
    $stranger = approvalHttpOwnedUser();
    Sanctum::actingAs($stranger, ['accounting.journal-approvals.read', 'accounting.journal-approvals.write']);

    $this->postJson('/api/v1/accounting/journal-approvals/', [
        'team_id' => $owner->current_team_id,
        'preparer_ref' => (string) $owner->getKey(),
        'approval_ref' => 'APR-SERVER-BOUND',
        'journal_type' => 'general',
        'journal_source' => 'accounting-general-ledger',
        'journal_ref' => 'J-NEW',
        'currency' => 'GBP',
        'amount' => '100.00',
    ])->assertCreated();

    $approval = JournalApproval::query()->where('approval_ref', 'APR-SERVER-BOUND')->firstOrFail();
    expect((int) $approval->team_id)->toBe((int) $stranger->current_team_id)
        ->and($approval->preparer_ref)->toBe((string) $stranger->getKey());
});

it('rejects request-supplied reviewer identities and HTTP-only posting', function (): void {
    $owner = approvalHttpOwnedUser();
    $approval = approvalHttpFixture($owner);
    Sanctum::actingAs($owner, ['accounting.journal-approvals.read', 'accounting.journal-approvals.write']);

    $this->postJson("/api/v1/accounting/journal-approvals/{$approval->id}/decide", [
        'actor_ref' => 'forged-approver',
        'approved' => true,
    ])->assertUnprocessable();

    $this->postJson("/api/v1/accounting/journal-approvals/{$approval->id}/post", [
        'emergency' => true,
        'reason' => 'Forged emergency posting',
    ])->assertForbidden();

    expect($approval->fresh()->status->value)->toBe('pending');
});

it('requires a trusted team owner for threshold changes and a write-capable token', function (): void {
    $owner = approvalHttpOwnedUser();
    $stranger = approvalHttpOwnedUser();
    Sanctum::actingAs($stranger, ['accounting.journal-approvals.read', 'accounting.journal-approvals.write']);
    $attributes = [
        'team_id' => $owner->current_team_id,
        'journal_type' => 'general',
        'minimum_amount' => '50.00',
        'reviewer_role' => 'accountant',
    ];

    $this->postJson('/api/v1/accounting/journal-approvals/thresholds', $attributes)
        ->assertForbidden();

    Sanctum::actingAs($owner, ['accounting.journal-approvals.read']);
    $this->postJson('/api/v1/accounting/journal-approvals/thresholds', [
        ...$attributes,
        'team_id' => $owner->current_team_id,
    ])->assertForbidden();
});

it('requires persisted team reviewer role and ignores caller-supplied role claims', function (): void {
    $preparer = approvalHttpOwnedUser();
    $reviewer = approvalHttpOwnedUser();
    $team = $preparer->currentTeam;
    $team->users()->attach($reviewer->getKey(), ['role' => 'viewer']);
    expect($reviewer->switchTeam($team))->toBeTrue();
    $reviewer->refresh();

    $approval = approvalHttpFixture($preparer);
    ApprovalThreshold::query()->create([
        'team_id' => $preparer->current_team_id,
        'journal_type' => 'general',
        'minimum_amount' => '50.00',
        'reviewer_role' => 'accountant',
        'required_approvals' => 1,
        'emergency_allowed' => false,
        'active' => true,
    ]);

    Sanctum::actingAs($reviewer, ['accounting.journal-approvals.read', 'accounting.journal-approvals.write']);

    // The submitted role is never used as approval authority.
    $this->postJson("/api/v1/accounting/journal-approvals/{$approval->id}/decide", [
        'approved' => true,
        'actor_roles' => ['accountant'],
    ])->assertUnprocessable()->assertJsonValidationErrors('approval');

    expect($approval->fresh()->status->value)->toBe('pending')
        ->and($approval->decisions()->count())->toBe(0);

    $team->users()->updateExistingPivot($reviewer->getKey(), ['role' => 'accountant']);

    $this->postJson("/api/v1/accounting/journal-approvals/{$approval->id}/decide", [
        'approved' => true,
    ])->assertOk()->assertJsonPath('data.status', 'approved');

    expect($approval->fresh()->decisions()->count())->toBe(1)
        ->and($approval->fresh()->decisions()->first()->actor_ref)->toBe((string) $reviewer->getKey())
        ->and($approval->fresh()->decisions()->first()->metadata['reviewer_role'])->toBe('accountant');
});
