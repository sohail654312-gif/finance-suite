<?php

declare(strict_types=1);

namespace Liberu\Accounting\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalEntity extends Model
{
    protected $table = 'accounting_legal_entities';

    protected $fillable = ['team_id', 'name', 'registration_number', 'currency_code', 'accounting_basis', 'is_active'];

    protected $casts = ['is_active' => 'bool'];

    protected static function booted(): void
    {
        static::updating(function (self $entity): void {
            if ($entity->isDirty('team_id')) {
                throw new \LogicException('Legal entity ownership cannot be changed after creation.');
            }
        });
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
