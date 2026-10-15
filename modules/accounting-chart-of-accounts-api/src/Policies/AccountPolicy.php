<?php

declare(strict_types=1);

namespace Liberu\Accounting\ChartOfAccountsApi\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Support\TenantOwnership;

final class AccountPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->can($user, 'accounting.chart.read')
            && TenantOwnership::currentTeamId($user) !== null;
    }

    public function view(?Authenticatable $user, Account $account): bool
    {
        return $this->can($user, 'accounting.chart.read')
            && TenantOwnership::owns($user, $account->getAttribute('team_id'));
    }

    public function create(?Authenticatable $user): bool
    {
        return $this->can($user, 'accounting.chart.write')
            && TenantOwnership::currentTeamId($user) !== null;
    }

    public function update(?Authenticatable $user, Account $account): bool
    {
        return $this->can($user, 'accounting.chart.write')
            && TenantOwnership::owns($user, $account->getAttribute('team_id'));
    }

    public function delete(?Authenticatable $user, Account $account): bool
    {
        return $this->can($user, 'accounting.chart.write')
            && TenantOwnership::owns($user, $account->getAttribute('team_id'));
    }

    private function can(?Authenticatable $user, string $ability): bool
    {
        return $user !== null && method_exists($user, 'tokenCan') && $user->tokenCan($ability);
    }
}
