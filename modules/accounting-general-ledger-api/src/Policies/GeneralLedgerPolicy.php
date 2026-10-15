<?php

declare(strict_types=1);

namespace Liberu\Accounting\GeneralLedgerApi\Policies;

use Liberu\Accounting\Core\Support\TenantOwnership;
use Liberu\Accounting\GeneralLedger\Models\JournalEntry;

class GeneralLedgerPolicy
{
    public function viewAny($user): bool
    {
        return $this->can($user, 'accounting.general-ledger.read')
            && TenantOwnership::currentTeamId($user) !== null;
    }

    public function view($user, JournalEntry $entry): bool
    {
        return $this->can($user, 'accounting.general-ledger.read')
            && TenantOwnership::owns($user, $entry->getAttribute('team_id'));
    }

    public function create($user): bool
    {
        return $this->can($user, 'accounting.general-ledger.write')
            && TenantOwnership::currentTeamId($user) !== null;
    }

    public function update($user, JournalEntry $entry): bool
    {
        return $this->can($user, 'accounting.general-ledger.write')
            && TenantOwnership::owns($user, $entry->getAttribute('team_id'));
    }

    public function delete($user, JournalEntry $entry): bool
    {
        return $this->can($user, 'accounting.general-ledger.write')
            && TenantOwnership::owns($user, $entry->getAttribute('team_id'));
    }

    private function can($user, string $ability): bool
    {
        return $user !== null && method_exists($user, 'tokenCan') && $user->tokenCan($ability);
    }
}
