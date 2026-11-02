<?php

declare(strict_types=1);

namespace Liberu\Accounting\FinancialStatements\Queries;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Support\TenantOwnership;

final class OwnedStatementBook
{
    public function authorizeAccount(int $bookId, int $accountId, ?Authenticatable $identity): void
    {
        $book = $this->handle($bookId, $identity);
        Account::query()->whereKey($accountId)->where('team_id', $book->getAttribute('team_id'))
            ->where('legal_entity_id', $book->getAttribute('legal_entity_id'))->firstOrFail();
    }

    public function handle(int $bookId, ?Authenticatable $identity): Book
    {
        $user = $identity instanceof Model ? $identity->fresh() : null;
        $teamId = TenantOwnership::currentTeamId($user);
        abort_if($teamId === null, 403);

        return Book::query()->whereKey($bookId)->where('team_id', $teamId)
            ->whereHas('legalEntity', fn ($query) => $query->where('team_id', $teamId))
            ->firstOrFail();
    }
}
