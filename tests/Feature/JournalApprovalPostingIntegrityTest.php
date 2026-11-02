<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\GeneralLedger\Actions\CreateJournal;
use Liberu\Accounting\GeneralLedger\Actions\PostJournal as PostLedgerJournal;
use Liberu\Accounting\GeneralLedger\Enums\JournalStatus;
use Liberu\Accounting\GeneralLedger\Exceptions\InvalidJournal;
use Liberu\Accounting\GeneralLedger\Models\JournalEntry;
use Liberu\Accounting\JournalApprovals\Actions\DecideJournal;
use Liberu\Accounting\JournalApprovals\Actions\SubmitJournal;
use Liberu\Accounting\JournalApprovals\Enums\ApprovalStatus;
use Liberu\Accounting\JournalApprovals\Exceptions\InvalidApproval;
use Liberu\Accounting\JournalApprovals\Models\ApprovalThreshold;

uses(RefreshDatabase::class);

function protectedLedgerJournal(string $amount = '100.00'): array
{
    $user = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $user->refresh();

    $entity = LegalEntity::query()->create([
        'team_id' => $user->current_team_id,
        'name' => 'Approval Entity',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
    ]);
    $book = Book::query()->create([
        'legal_entity_id' => $entity->id,
        'name' => 'Approval Book',
        'code' => 'APPROVAL',
        'accounting_basis' => 'accrual',
    ]);
    $cash = Account::query()->create([
        'legal_entity_id' => $entity->id,
        'code' => '1000',
        'name' => 'Cash',
        'type' => 'asset',
        'normal_balance' => 'debit',
    ]);
    $revenue = Account::query()->create([
        'legal_entity_id' => $entity->id,
        'code' => '4000',
        'name' => 'Revenue',
        'type' => 'revenue',
        'normal_balance' => 'credit',
    ]);

    $journal = app(CreateJournal::class)->handle([
        'book_id' => $book->id,
        'entry_date' => '2026-10-08',
        'description' => 'Protected posting candidate',
    ], [
        ['account_id' => $cash->id, 'debit' => $amount, 'credit' => '0.00'],
        ['account_id' => $revenue->id, 'debit' => '0.00', 'credit' => $amount],
    ]);

    return [$user, $journal];
}

function protectJournal(JournalEntry $journal, int $requiredApprovals = 1): ApprovalThreshold
{
    return ApprovalThreshold::query()->create([
        'team_id' => $journal->team_id,
        'journal_type' => $journal->journal_type->value,
        'minimum_amount' => '50.00',
        'reviewer_role' => 'accountant',
        'required_approvals' => $requiredApprovals,
        'emergency_allowed' => false,
        'active' => true,
    ]);
}

function submitLedgerApproval(JournalEntry $journal, string $amount = '100.00', string $preparer = 'preparer-1', string $currency = 'GBP')
{
    return app(SubmitJournal::class)->handle([
        'team_id' => $journal->team_id,
        'approval_ref' => 'APR-'.$journal->getKey(),
        'journal_type' => $journal->journal_type->value,
        'journal_source' => 'accounting-general-ledger',
        'journal_ref' => (string) $journal->getKey(),
        'preparer_ref' => $preparer,
        'currency' => $currency,
        'amount' => $amount,
    ]);
}

it('blocks a protected ledger journal when no linked approval exists', function (): void {
    [, $journal] = protectedLedgerJournal();
    protectJournal($journal);

    expect(fn () => app(PostLedgerJournal::class)->handle($journal, 'poster-1'))
        ->toThrow(InvalidJournal::class, 'requires an approved posting authorization');

    expect($journal->refresh()->status)->toBe(JournalStatus::Draft);
});

it('rejects self approval and reviewers without the configured role', function (): void {
    [, $journal] = protectedLedgerJournal();
    protectJournal($journal);
    $approval = submitLedgerApproval($journal);

    expect(fn () => app(DecideJournal::class)->handle($approval, 'preparer-1', true, null, ['accountant']))
        ->toThrow(InvalidApproval::class, 'cannot approve their own journal');

    expect(fn () => app(DecideJournal::class)->handle($approval, 'reviewer-1', true, null, ['viewer']))
        ->toThrow(InvalidApproval::class, 'required approval role');

    expect($approval->refresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->decisions()->count())->toBe(0);
});

it('requires the configured number of distinct qualified approvals', function (): void {
    [, $journal] = protectedLedgerJournal();
    protectJournal($journal, 2);
    $approval = submitLedgerApproval($journal);

    $approval = app(DecideJournal::class)->handle($approval, 'reviewer-1', true, null, ['accountant']);
    expect($approval->status)->toBe(ApprovalStatus::Pending);

    expect(fn () => app(PostLedgerJournal::class)->handle($journal, 'poster-1'))
        ->toThrow(InvalidJournal::class, 'requires an approved posting authorization');

    $approval = app(DecideJournal::class)->handle($approval, 'reviewer-2', true, null, ['accountant']);
    expect($approval->status)->toBe(ApprovalStatus::Approved);

    $posted = app(PostLedgerJournal::class)->handle($journal, 'poster-1');

    expect($posted->status)->toBe(JournalStatus::Posted)
        ->and($approval->refresh()->status)->toBe(ApprovalStatus::Posted)
        ->and($approval->decisions()->count())->toBe(2);
});

it('rejects an approval whose amount does not match the protected journal', function (): void {
    [, $journal] = protectedLedgerJournal();
    protectJournal($journal);
    $approval = submitLedgerApproval($journal, '99.00');
    $approval = app(DecideJournal::class)->handle($approval, 'reviewer-1', true, null, ['accountant']);

    expect($approval->status)->toBe(ApprovalStatus::Approved);

    expect(fn () => app(PostLedgerJournal::class)->handle($journal, 'poster-1'))
        ->toThrow(InvalidJournal::class, 'does not match the posting candidate');

    expect($journal->refresh()->status)->toBe(JournalStatus::Draft)
        ->and($approval->refresh()->status)->toBe(ApprovalStatus::Approved);
});

it('rejects an approved journal authorization denominated in another currency', function (): void {
    [, $journal] = protectedLedgerJournal();
    protectJournal($journal);
    $approval = submitLedgerApproval($journal, '100.00', 'preparer-1', 'USD');
    $approval = app(DecideJournal::class)->handle($approval, 'reviewer-1', true, null, ['accountant']);

    expect($approval->status)->toBe(ApprovalStatus::Approved);
    expect(fn () => app(PostLedgerJournal::class)->handle($journal, 'poster-1'))
        ->toThrow(InvalidJournal::class, 'does not match the posting candidate');

    expect($journal->refresh()->status)->toBe(JournalStatus::Draft)
        ->and($approval->refresh()->status)->toBe(ApprovalStatus::Approved);
});

it('does not require approval below a configured posting threshold', function (): void {
    [, $journal] = protectedLedgerJournal('25.00');
    protectJournal($journal);

    $posted = app(PostLedgerJournal::class)->handle($journal, 'poster-1');

    expect($posted->status)->toBe(JournalStatus::Posted);
});

it('rejects sub-cent approval amounts without floating-point rounding', function (): void {
    [, $journal] = protectedLedgerJournal();

    expect(fn () => submitLedgerApproval($journal, '100.005'))
        ->toThrow(InvalidApproval::class, 'positive journal amount');

    expect(fn () => submitLedgerApproval($journal, '1e2'))
        ->toThrow(InvalidApproval::class, 'positive journal amount');
});

it('keeps an approval reference bound to its original journal and exact amount', function (): void {
    [, $journal] = protectedLedgerJournal();

    $approval = submitLedgerApproval($journal, '100.00');
    $retry = submitLedgerApproval($journal, '100.00');
    expect($retry->getKey())->toBe($approval->getKey());

    expect(fn () => submitLedgerApproval($journal, '100.01'))
        ->toThrow(InvalidApproval::class, 'different financial terms');

    expect(fn () => app(SubmitJournal::class)->handle([
        'team_id' => $journal->team_id,
        'approval_ref' => 'APR-'.$journal->getKey(),
        'journal_type' => $journal->journal_type->value,
        'journal_source' => 'accounting-general-ledger',
        'journal_ref' => 'other-journal',
        'preparer_ref' => 'preparer-1',
        'currency' => 'GBP',
        'amount' => '100.00',
    ]))->toThrow(InvalidApproval::class, 'different journal');

    expect($approval->fresh()->amount)->toBe('100.00')
        ->and($approval->fresh()->decisions()->count())->toBe(0);
});
