<?php

declare(strict_types=1);

namespace Liberu\Accounting\JournalApprovals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Liberu\Accounting\JournalApprovals\Exceptions\InvalidApproval;

final class ApprovalAuthority
{
    public function selectedTeamId(): ?int
    {
        $user = Auth::user();
        if (! $user instanceof Model) {
            return null;
        }

        $persisted = $user->newQuery()->find($user->getKey());
        $teamId = $persisted?->getAttribute('current_team_id');
        if (! is_numeric($teamId) || (int) $teamId < 1
            || $this->persistedRole((int) $teamId, (string) $user->getAuthIdentifier()) === null) {
            return null;
        }

        return (int) $teamId;
    }

    public function reviewerRole(int $teamId, string $actor): string
    {
        $user = Auth::user();
        if (! $user instanceof Model || (string) $user->getAuthIdentifier() !== $actor
            || $this->selectedTeamId() !== $teamId) {
            throw new InvalidApproval('An authenticated member of the selected journal team must decide the approval.');
        }

        // Selection can change after the initial ownership lookup. Decisions
        // and configuration run in a transaction, so retain this identity lock
        // until the authorization and resulting write have both completed.
        $selectedTeamId = DB::table('users')->where('id', $actor)->lockForUpdate()->value('current_team_id');
        if (! is_numeric($selectedTeamId) || (int) $selectedTeamId !== $teamId) {
            throw new InvalidApproval('The selected journal team changed before authorization.');
        }

        $role = $this->persistedRole($teamId, $actor, true);
        if ($role === null) {
            throw new InvalidApproval('The reviewer is no longer a member of the journal team.');
        }

        return $role;
    }

    public function persistedRole(int $teamId, string $actor, bool $lock = false): ?string
    {
        if (! ctype_digit($actor) || (int) $actor < 1) {
            return null;
        }

        $identity = DB::table('users')->where('id', $actor)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($identity === null) {
            return null;
        }

        $team = DB::table('teams')->where('id', $teamId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($team === null) {
            return null;
        }
        if ((string) $team->user_id === $actor) {
            return 'owner';
        }

        $membership = DB::table('team_user')->where('team_id', $teamId)->where('user_id', $actor)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $membership !== null && is_string($membership->role) && $membership->role !== ''
            ? $membership->role : null;
    }
}
