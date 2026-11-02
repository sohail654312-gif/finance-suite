<?php

declare(strict_types=1);

namespace Liberu\Accounting\CoreApi\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Support\TenantOwnership;

final class AccountingCorePolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->can($user, 'accounting.core.read')
            && TenantOwnership::currentTeamId($user) !== null;
    }

    public function view(?Authenticatable $user, mixed $model = null): bool
    {
        return $this->can($user, 'accounting.core.read') && $this->owns($user, $model);
    }

    public function create(?Authenticatable $user): bool
    {
        return $this->can($user, 'accounting.core.write')
            && TenantOwnership::currentTeamId($user) !== null;
    }

    public function update(?Authenticatable $user, mixed $model = null): bool
    {
        return $this->can($user, 'accounting.core.write') && $this->owns($user, $model);
    }

    public function delete(?Authenticatable $user, mixed $model = null): bool
    {
        return $this->can($user, 'accounting.core.write') && $this->owns($user, $model);
    }

    private function can(?Authenticatable $user, string $ability): bool
    {
        return $user !== null && method_exists($user, 'tokenCan') && $user->tokenCan($ability);
    }

    private function owns(?Authenticatable $user, mixed $model): bool
    {
        if (! $model instanceof Model) {
            return false;
        }

        if ($model instanceof Book) {
            return TenantOwnership::owns($user, $model->getAttribute('team_id'));
        }

        if (method_exists($model, 'book')) {
            $book = $model->getRelationValue('book');

            return $book instanceof Book
                && TenantOwnership::owns($user, $book->getAttribute('team_id'));
        }

        return false;
    }
}
