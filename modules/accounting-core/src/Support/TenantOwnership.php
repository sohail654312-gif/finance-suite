<?php

declare(strict_types=1);

namespace Liberu\Accounting\Core\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class TenantOwnership
{
    public static function currentTeamId(?Authenticatable $user): ?int
    {
        if (! $user instanceof Model || ! method_exists($user, 'belongsToTeam')) {
            return null;
        }

        $rawTeamId = $user->getAttribute('current_team_id');
        if (! is_numeric($rawTeamId) || (int) $rawTeamId < 1) {
            return null;
        }

        $team = $user->getRelationValue('currentTeam');
        if (! $team instanceof Model || ! $user->belongsToTeam($team)) {
            return null;
        }

        return (int) $rawTeamId;
    }

    public static function owns(?Authenticatable $user, mixed $teamId): bool
    {
        $currentTeamId = self::currentTeamId($user);

        return $currentTeamId !== null
            && is_numeric($teamId)
            && $currentTeamId === (int) $teamId;
    }
}
