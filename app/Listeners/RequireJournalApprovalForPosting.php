<?php

declare(strict_types=1);

namespace App\Listeners;

use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\GeneralLedger\Events\AuthorizingJournalPost;
use Liberu\Accounting\GeneralLedger\Exceptions\InvalidJournal;
use Liberu\Accounting\JournalApprovals\Enums\ApprovalStatus;
use Liberu\Accounting\JournalApprovals\Enums\DecisionType;
use Liberu\Accounting\JournalApprovals\Models\ApprovalThreshold;
use Liberu\Accounting\JournalApprovals\Models\JournalApproval;

final class RequireJournalApprovalForPosting
{
    public function handle(AuthorizingJournalPost $event): void
    {
        $journal = $event->journal;
        $teamId = $journal->getAttribute('team_id');

        // Historical unowned modular rows are not guessed into a tenant. They remain
        // outside the protected multi-tenant API path until an explicit ownership
        // migration is performed.
        if (! is_numeric($teamId) || (int) $teamId < 1) {
            return;
        }

        $amount = $journal->totalDebits();
        $threshold = ApprovalThreshold::query()
            ->where('team_id', (int) $teamId)
            ->where('journal_type', $journal->journal_type->value)
            ->where('active', true)
            ->lockForUpdate()
            ->orderByDesc('minimum_amount')
            ->get()
            ->first(fn (ApprovalThreshold $candidate): bool => bccomp($amount, (string) $candidate->getAttribute('minimum_amount'), 2) >= 0);

        if (! $threshold instanceof ApprovalThreshold) {
            return;
        }

        $approval = JournalApproval::query()
            ->where('team_id', (int) $teamId)
            ->where('journal_source', 'accounting-general-ledger')
            ->where('journal_ref', (string) $journal->getKey())
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if (! $approval instanceof JournalApproval || $approval->status !== ApprovalStatus::Approved) {
            throw new InvalidJournal('This journal requires an approved posting authorization.');
        }

        // An approval for the right amount but the wrong currency is not
        // authorization to post this book's journal. Always use the entity's
        // persisted currency, not a caller-supplied posting attribute.
        $book = $journal->book()->with('legalEntity')->first();
        $entity = $book?->getRelation('legalEntity');
        $bookCurrency = $entity instanceof LegalEntity
            ? $entity->getAttribute('currency_code')
            : null;
        if (! is_string($bookCurrency) || $bookCurrency === ''
            || strtoupper((string) $approval->currency) !== strtoupper($bookCurrency)
            || $approval->journal_type !== $journal->journal_type->value
            || bccomp((string) $approval->amount, $amount, 2) !== 0) {
            throw new InvalidJournal('The journal approval does not match the posting candidate.');
        }

        $qualifiedApprovals = $approval->decisions()
            ->where('decision', DecisionType::Approved->value)
            ->get()
            ->filter(fn ($decision): bool => $decision->getAttribute('actor_ref') !== $approval->preparer_ref)
            ->filter(fn ($decision): bool => data_get($decision->getAttribute('metadata'), 'reviewer_role') === $threshold->getAttribute('reviewer_role'))
            ->unique('actor_ref')
            ->count();

        if ($qualifiedApprovals < $threshold->getAttribute('required_approvals')) {
            throw new InvalidJournal('The journal does not have enough qualified approvals.');
        }

        $approval->update([
            'status' => ApprovalStatus::Posted,
            'posted_at' => now(),
        ]);
    }
}
