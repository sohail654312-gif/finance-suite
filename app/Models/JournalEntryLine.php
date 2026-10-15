<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Account $account
 */
class JournalEntryLine extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'debit_amount',
        'credit_amount',
        'description',
        'department',
        'cost_center',
    ];

    #[\Override]
    protected $casts = [
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            self::assertLegacyParentNotPosted($line);
            self::assertExactCentValues($line);
        });
        static::deleting(function (self $line): void {
            self::assertLegacyParentNotPosted($line);
        });
    }

    private static function assertExactCentValues(self $line): void
    {
        // Validate raw values before Laravel's decimal:2 accessor or database
        // column can silently round away a fraction of a cent.
        $attributes = $line->getAttributes();
        foreach (['debit_amount', 'credit_amount'] as $field) {
            if (array_key_exists($field, $attributes)
                && preg_match('/^-?\\d{1,18}(?:\\.\\d{1,2})?$/D', (string) $attributes[$field]) !== 1) {
                throw new \DomainException('Journal amounts must use exact cents.');
            }
        }
    }

    private static function assertLegacyParentNotPosted(self $line): void
    {
        // Protect both original and destination journals so reparenting an
        // existing row cannot change the history of a posted entry.
        $ids = array_unique([
            (int) $line->getAttribute('journal_entry_id'),
            (int) $line->getRawOriginal('journal_entry_id'),
        ]);

        foreach ($ids as $id) {
            if ($id > 0 && JournalEntry::query()->whereKey($id)->where('is_posted', true)->exists()) {
                throw new \DomainException('Posted journal entry lines are immutable.');
            }
        }
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function getAmountAttribute()
    {
        return $this->debit_amount > 0 ? $this->debit_amount : $this->credit_amount;
    }

    public function getTypeAttribute(): string
    {
        return $this->debit_amount > 0 ? 'debit' : 'credit';
    }
}
