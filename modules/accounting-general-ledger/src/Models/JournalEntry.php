<?php

declare(strict_types=1);

namespace Liberu\Accounting\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\GeneralLedger\Enums\JournalStatus;
use Liberu\Accounting\GeneralLedger\Enums\JournalType;

/**
 * @property int|string $book_id
 * @property string $entry_number
 * @property JournalStatus $status
 * @property JournalType $journal_type
 * @property string|null $description
 */
class JournalEntry extends Model
{
    protected $table = 'accounting_journal_entries';

    protected $fillable = ['team_id', 'book_id', 'entry_number', 'entry_date', 'journal_type', 'status', 'description', 'source_type', 'source_id', 'reversal_of_id', 'posted_by', 'posted_at', 'metadata'];

    protected $casts = ['entry_date' => 'date', 'posted_at' => 'datetime', 'metadata' => 'array', 'status' => JournalStatus::class, 'journal_type' => JournalType::class];

    protected static function booted(): void
    {
        static::creating(function (self $journal): void {
            $book = Book::query()->find($journal->book_id);
            if ($book === null) {
                throw new \LogicException('A journal requires an existing book.');
            }
            $journal->setAttribute('team_id', $book->getAttribute('team_id'));
        });
        static::creating(function (self $journal): void {
            if (blank($journal->entry_number)) {
                $year = $journal->entry_date?->format('Y') ?? now()->format('Y');
                $prefix = 'JE-'.$year.'-';
                $last = self::query()->where('book_id', $journal->book_id)->where('entry_number', 'like', $prefix.'%')->lockForUpdate()->max('entry_number');
                $journal->entry_number = $prefix.str_pad((string) (((int) substr((string) $last, -6)) + 1), 6, '0', STR_PAD_LEFT);
            }
        });
        static::updating(function (self $journal): void {
            if ($journal->isDirty('team_id')) {
                throw new \LogicException('Journal ownership cannot be changed after creation.');
            }
            if ($journal->isDirty('book_id')) {
                throw new \LogicException('Journal book ownership is immutable after creation.');
            }

            $original = $journal->getRawOriginal('status');
            if ($original === JournalStatus::Draft->value && $journal->isDirty('status')) {
                $allowed = ['status', 'posted_by', 'posted_at', 'updated_at'];
                if (array_diff(array_keys($journal->getDirty()), $allowed) !== []) {
                    throw new \LogicException('Posting may only change the journal lifecycle fields.');
                }

                return;
            }
            if ($original !== JournalStatus::Draft->value && $journal->isDirty()) {
                throw new \LogicException('Posted or reversed journals are immutable.');
            }
        });
        static::deleting(function (self $journal): void {
            if ($journal->status !== JournalStatus::Draft) {
                throw new \LogicException('Posted or reversed journals cannot be deleted.');
            }
        });
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function recurringJournals(): HasMany
    {
        return $this->hasMany(RecurringJournal::class, 'book_id', 'book_id');
    }

    public function isBalanced(): bool
    {
        $debits = '0.00';
        $credits = '0.00';
        $lines = $this->lines()->get();
        foreach ($lines as $line) {
            $debit = (string) $line->debit;
            $credit = (string) $line->credit;
            if (! preg_match('/^\d{1,18}(?:\.\d{1,2})?$/D', $debit) || ! preg_match('/^\d{1,18}(?:\.\d{1,2})?$/D', $credit) || (bccomp($debit, '0', 2) === 1) === (bccomp($credit, '0', 2) === 1)) {
                return false;
            }
            $debits = bcadd($debits, $debit, 2);
            $credits = bcadd($credits, $credit, 2);
        }

        return $lines->count() >= 2 && bccomp($debits, $credits, 2) === 0;
    }

    public function totalDebits(): string
    {
        return $this->lines()->get()->reduce(fn (string $total, JournalLine $line): string => bcadd($total, (string) $line->debit, 2), '0.00');
    }

    public function totalCredits(): string
    {
        return $this->lines()->get()->reduce(fn (string $total, JournalLine $line): string => bcadd($total, (string) $line->credit, 2), '0.00');
    }
}
