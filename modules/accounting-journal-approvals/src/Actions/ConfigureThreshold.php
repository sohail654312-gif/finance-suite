<?php

declare(strict_types=1);

namespace Liberu\Accounting\JournalApprovals\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Liberu\Accounting\JournalApprovals\ApprovalAuthority;
use Liberu\Accounting\JournalApprovals\Exceptions\InvalidApproval;
use Liberu\Accounting\JournalApprovals\Models\ApprovalThreshold;

final class ConfigureThreshold
{
    public function __construct(private readonly ApprovalAuthority $authority) {}

    public function handle(array $attributes): ApprovalThreshold
    {
        $amount = trim((string) ($attributes['minimum_amount'] ?? ''));
        $required = filter_var($attributes['required_approvals'] ?? 1, FILTER_VALIDATE_INT);
        $teamId = filter_var($attributes['team_id'] ?? null, FILTER_VALIDATE_INT);
        if (blank($attributes['journal_type'] ?? null)
            || preg_match('/^\d{1,18}(?:\.\d{1,2})?$/D', $amount) !== 1
            || blank($attributes['reviewer_role'] ?? null) || $required === false || $required < 1
            || $teamId === false || $teamId < 1) {
            throw new InvalidApproval('Threshold requires journal type, non-negative amount, and reviewer role.');
        }

        return DB::transaction(function () use ($attributes, $amount, $required, $teamId): ApprovalThreshold {
            if ($this->authority->reviewerRole($teamId, (string) Auth::id()) !== 'owner') {
                throw new InvalidApproval('Only the persisted team owner can configure journal approval thresholds.');
            }

            return ApprovalThreshold::updateOrCreate(['team_id' => $teamId, 'journal_type' => $attributes['journal_type']], ['minimum_amount' => $amount, 'reviewer_role' => $attributes['reviewer_role'], 'required_approvals' => $required, 'emergency_allowed' => $attributes['emergency_allowed'] ?? false, 'active' => $attributes['active'] ?? true, 'metadata' => $attributes['metadata'] ?? null]);
        });
    }
}
