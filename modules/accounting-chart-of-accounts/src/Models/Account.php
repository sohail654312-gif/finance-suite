<?php

declare(strict_types=1);

namespace Liberu\Accounting\ChartOfAccounts\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Liberu\Accounting\ChartOfAccounts\Enums\AccountType;
use Liberu\Accounting\ChartOfAccounts\Enums\NormalBalance;
use Liberu\Accounting\Core\Models\LegalEntity;

/**
 * @property int $legal_entity_id
 * @property string $code
 * @property string $name
 * @property AccountType|null $type
 * @property int|null $parent_id
 * @property bool $allow_manual_entry
 * @property bool $is_active
 */
class Account extends Model
{
    protected $table = 'accounting_chart_accounts';

    protected $fillable = [
        'team_id', 'legal_entity_id', 'code', 'name', 'description', 'type', 'normal_balance',
        'parent_id', 'is_control_account', 'allow_manual_entry', 'is_active', 'locale', 'metadata',
    ];

    protected $attributes = [
        'is_control_account' => false,
        'allow_manual_entry' => true,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $entity = LegalEntity::query()->find($account->legal_entity_id);
            if ($entity === null) {
                throw new \LogicException('An account requires an existing legal entity.');
            }

            $ownerTeamId = $entity->getAttribute('team_id');
            if ($account->exists) {
                $originalTeamId = $account->getRawOriginal('team_id');
                if ($originalTeamId !== null && (string) $originalTeamId !== (string) $ownerTeamId) {
                    throw new \LogicException('An account cannot be moved across tenant ownership boundaries.');
                }
                if ($account->isDirty('team_id') && (string) $account->getAttribute('team_id') !== (string) $ownerTeamId) {
                    throw new \LogicException('Account ownership must match its legal entity.');
                }
            }

            $account->setAttribute('team_id', $ownerTeamId);
        });
    }

    protected $casts = [
        'type' => AccountType::class,
        'normal_balance' => NormalBalance::class,
        'is_control_account' => 'bool',
        'allow_manual_entry' => 'bool',
        'is_active' => 'bool',
        'metadata' => 'array',
    ];

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
