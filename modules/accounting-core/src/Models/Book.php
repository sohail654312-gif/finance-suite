<?php

declare(strict_types=1);

namespace Liberu\Accounting\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    protected $table = 'accounting_books';

    protected $fillable = ['team_id', 'legal_entity_id', 'name', 'code', 'accounting_basis', 'is_active'];

    protected $casts = ['is_active' => 'bool'];

    protected static function booted(): void
    {
        static::saving(function (self $book): void {
            $entity = LegalEntity::query()->find($book->legal_entity_id);
            if ($entity === null) {
                throw new \LogicException('A book requires an existing legal entity.');
            }

            $ownerTeamId = $entity->getAttribute('team_id');
            if ($book->exists) {
                $originalTeamId = $book->getRawOriginal('team_id');
                if ($originalTeamId !== null && (string) $originalTeamId !== (string) $ownerTeamId) {
                    throw new \LogicException('A book cannot be moved across tenant ownership boundaries.');
                }
                if ($book->isDirty('team_id') && (string) $book->getAttribute('team_id') !== (string) $ownerTeamId) {
                    throw new \LogicException('Book ownership must match its legal entity.');
                }
            }

            $book->setAttribute('team_id', $ownerTeamId);
        });
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function fiscalCalendars(): HasMany
    {
        return $this->hasMany(FiscalCalendar::class);
    }

    public function numberingSequences(): HasMany
    {
        return $this->hasMany(NumberingSequence::class);
    }

    public function defaults(): HasMany
    {
        return $this->hasMany(AccountingDefault::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(AccountingPolicy::class);
    }
}
