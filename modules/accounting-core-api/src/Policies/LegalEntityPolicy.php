<?php

declare(strict_types=1);

namespace Liberu\Accounting\CoreApi\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Core\Support\TenantOwnership;

final class LegalEntityPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->hasAbility($user, 'accounting.core.read')
            && TenantOwnership::currentTeamId($user) !== null;
    }

    public function view(?Authenticatable $user, LegalEntity $entity): bool
    {
        return $this->hasAbility($user, 'accounting.core.read')
            && TenantOwnership::owns($user, $entity->getAttribute('team_id'));
    }

    public function create(?Authenticatable $user): bool
    {
        return $this->hasAbility($user, 'accounting.core.write')
            && TenantOwnership::currentTeamId($user) !== null;
    }

    public function update(?Authenticatable $user, LegalEntity $entity): bool
    {
        return $this->hasAbility($user, 'accounting.core.write')
            && TenantOwnership::owns($user, $entity->getAttribute('team_id'));
    }

    public function delete(?Authenticatable $user, LegalEntity $entity): bool
    {
        return $this->hasAbility($user, 'accounting.core.write')
            && TenantOwnership::owns($user, $entity->getAttribute('team_id'));
    }

    private function hasAbility(?Authenticatable $user, string $ability): bool
    {
        return $user !== null
            && method_exists($user, 'tokenCan')
            && $user->tokenCan($ability);
    }
}
