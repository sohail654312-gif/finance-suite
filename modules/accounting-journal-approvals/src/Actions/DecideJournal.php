<?php

declare(strict_types=1);

namespace Liberu\Accounting\JournalApprovals\Actions;

use Illuminate\Support\Facades\DB;
use Liberu\Accounting\JournalApprovals\ApprovalAuthority;
use Liberu\Accounting\JournalApprovals\Enums\ApprovalStatus;
use Liberu\Accounting\JournalApprovals\Enums\DecisionType;
use Liberu\Accounting\JournalApprovals\Events\JournalApproved;
use Liberu\Accounting\JournalApprovals\Exceptions\InvalidApproval;
use Liberu\Accounting\JournalApprovals\Models\ApprovalThreshold;
use Liberu\Accounting\JournalApprovals\Models\JournalApproval;

final class DecideJournal
{
    public function __construct(private readonly ApprovalAuthority $authority) {}

    /**
     * @param  list<string>  $actorRoles  Compatibility parameter; qualifications are always resolved from persisted membership.
     */
    public function handle(
        JournalApproval $approval,
        string $actor,
        bool $approved,
        ?string $comment = null,
        array $actorRoles = [],
    ): JournalApproval {
        return DB::transaction(function () use ($approval, $actor, $approved, $comment): JournalApproval {
            $approval = JournalApproval::query()
                ->whereKey($approval->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($approval->status !== ApprovalStatus::Pending) {
                throw new InvalidApproval('Only pending journals can be decided.');
            }
            if ($actor === $approval->preparer_ref) {
                throw new InvalidApproval('A journal preparer cannot approve their own journal.');
            }
            if (! $approved && blank($comment)) {
                throw new InvalidApproval('Rejection requires a comment.');
            }
            if ($approval->decisions()->where('actor_ref', $actor)->exists()) {
                throw new InvalidApproval('The same reviewer cannot decide the journal more than once.');
            }

            if (! is_numeric($approval->team_id) || (int) $approval->team_id < 1) {
                throw new InvalidApproval('A journal approval must belong to a team before it can be decided.');
            }
            $reviewerRole = $this->authority->reviewerRole((int) $approval->team_id, $actor);

            $threshold = $this->applicableThreshold($approval);
            if ($threshold instanceof ApprovalThreshold
                && $threshold->reviewer_role !== $reviewerRole) {
                throw new InvalidApproval('The reviewer does not satisfy the required approval role.');
            }

            $approval->decisions()->create([
                'actor_ref' => $actor,
                'decision' => $approved ? DecisionType::Approved : DecisionType::Rejected,
                'comment' => $comment,
                'decided_at' => now(),
                'metadata' => ['reviewer_role' => $reviewerRole],
            ]);

            if (! $approved) {
                $approval->update([
                    'status' => ApprovalStatus::Rejected,
                    'reviewer_ref' => $actor,
                    'decided_at' => now(),
                ]);

                return $approval->refresh();
            }

            $required = $threshold?->required_approvals ?? 1;
            $approvedCount = $approval->decisions()
                ->where('decision', DecisionType::Approved->value)
                ->distinct()
                ->count('actor_ref');

            if ($approvedCount >= $required) {
                $approval->update([
                    'status' => ApprovalStatus::Approved,
                    'reviewer_ref' => $actor,
                    'decided_at' => now(),
                ]);
                $result = $approval->refresh();
                DB::afterCommit(fn () => event(new JournalApproved($result)));

                return $result;
            }

            return $approval->refresh();
        });
    }

    private function applicableThreshold(JournalApproval $approval): ?ApprovalThreshold
    {
        if (! is_numeric($approval->team_id) || (int) $approval->team_id < 1) {
            return null;
        }

        return ApprovalThreshold::query()
            ->where('team_id', (int) $approval->team_id)
            ->where('journal_type', $approval->journal_type)
            ->where('active', true)
            ->lockForUpdate()
            ->orderByDesc('minimum_amount')
            ->get()
            ->first(fn (ApprovalThreshold $threshold): bool => bccomp((string) $approval->amount, (string) $threshold->minimum_amount, 2) >= 0);
    }
}
