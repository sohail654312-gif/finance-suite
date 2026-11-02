<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\Approvable;
use App\Contracts\ApprovableRecord;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int|null $team_id
 * @property-read Collection<int, JournalEntryLine> $lines
 */
class JournalEntry extends Model implements ApprovableRecord
{
    use Approvable;
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = [
        'entry_number',
        'entry_date',
        'reference_number',
        'memo',
        'entry_type',
        'counterparty_team_id',
        'approval_status',
        'rejection_reason',
        'team_id',
        'user_id',
        'is_posted',
    ];

    #[\Override]
    protected $casts = [
        'entry_date' => 'date',
        'is_approved' => 'boolean',
        'is_posted' => 'boolean',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    #[\Override]
    protected static function boot()
    {
        parent::boot();

        static::creating(function (JournalEntry $journalEntry): void {
            if (empty($journalEntry->user_id) && auth()->check()) {
                $journalEntry->user_id = auth()->id();
            }

            // ponytail: stamp tenant team on non-Filament creates (Filament stamps panel creates itself); additive, leaves DB default when tenantless.
            if (empty($journalEntry->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $journalEntry->team_id = $team->getKey();
            }

            if (! $journalEntry->entry_number) {
                $journalEntry->entry_number = static::generateEntryNumber();
            }
        });

        // Period close: block any save (create/edit/post/reverse all route through save())
        // touching a date before the owning team's lock. No lock set = no-op, existing flows untouched.
        // ponytail: per-team lock; pure line edits via $entry->lines()->create() don't touch the parent
        // so they skip this guard — acceptable because posted entries are already immutable. Upgrade path:
        // add a JournalEntryLine saving() guard if line-level backdating ever becomes possible.
        static::saving(function (JournalEntry $journalEntry): void {
            // currentTeam() is a loose Jetstream relation (bare Model), so narrow to Team.
            // books_locked_before is a nullable date; normalise to Carbon so the compare
            // and format are type-safe. entry_date is a non-null date cast.
            $team = $journalEntry->team ?? auth()->user()?->currentTeam;
            $lock = $team instanceof Team && $team->books_locked_before
                ? Carbon::parse($team->books_locked_before)
                : null;
            $entryDate = $journalEntry->entry_date;

            if ($lock && $entryDate->lt($lock)) {
                throw new \DomainException(sprintf(
                    'Cannot save journal entry dated %s: the books are closed before %s.',
                    $entryDate->toDateString(),
                    $lock->toDateString(),
                ));
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function counterpartyTeam()
    {
        return $this->belongsTo(Team::class, 'counterparty_team_id');
    }

    public function approvalAmount(): float
    {
        return (float) $this->lines()->sum('debit_amount');
    }

    public function getTotalDebitsAttribute()
    {
        return $this->lines()->sum('debit_amount');
    }

    public function getTotalCreditsAttribute()
    {
        return $this->lines()->sum('credit_amount');
    }

    public function isBalanced(): bool
    {
        // An empty journal is not balanced double-entry accounting merely
        // because both totals happen to equal zero.
        $lines = $this->lines()->get(['debit_amount', 'credit_amount']);
        if ($lines->count() < 2) {
            return false;
        }

        $debits = '0.00';
        $credits = '0.00';

        foreach ($lines as $line) {
            // Inspect persisted values without the decimal:2 accessor hiding
            // an imprecise legacy/SQL-imported fractional-cent amount.
            $debit = (string) $line->getRawOriginal('debit_amount');
            $credit = (string) $line->getRawOriginal('credit_amount');
            if (preg_match('/^\\d{1,18}(?:\\.\\d{1,2})?$/D', $debit) !== 1
                || preg_match('/^\\d{1,18}(?:\\.\\d{1,2})?$/D', $credit) !== 1
                || (bccomp($debit, '0.00', 2) > 0) === (bccomp($credit, '0.00', 2) > 0)) {
                return false;
            }

            $debits = bcadd($debits, $debit, 2);
            $credits = bcadd($credits, $credit, 2);
        }

        return bccomp($debits, '0.00', 2) > 0
            && bccomp($debits, $credits, 2) === 0;
    }

    public function post(): static
    {
        if ($this->is_posted) {
            throw new \Exception('Journal entry is already posted.');
        }

        if (! $this->isBalanced()) {
            throw new \Exception('Journal entry must be balanced before posting.');
        }

        \DB::transaction(function (): void {
            // Validate every posting account before mutating balances.
            // A balanced journal must not include another company's accounts.
            foreach ($this->lines as $line) {
                $account = $line->account;
                if ((string) $account->team_id !== (string) $this->team_id) {
                    throw new \DomainException('Journal accounts must belong to the posting team.');
                }
            }

            foreach ($this->lines as $line) {
                $account = $line->account;

                if ($account->normal_balance === 'debit') {
                    $account->balance += $line->debit_amount - $line->credit_amount;
                } else {
                    $account->balance += $line->credit_amount - $line->debit_amount;
                }

                $account->save();
            }

            $this->is_posted = true;
            $this->posted_at = now();
            $this->save();
        });

        return $this;
    }

    public function reverse(): static
    {
        if (! $this->is_posted) {
            throw new \Exception('Cannot reverse an unposted journal entry.');
        }

        \DB::transaction(function (): void {
            foreach ($this->lines as $line) {
                $account = $line->account;

                if ($account->normal_balance === 'debit') {
                    $account->balance -= $line->debit_amount - $line->credit_amount;
                } else {
                    $account->balance -= $line->credit_amount - $line->debit_amount;
                }

                $account->save();
            }

            $this->is_posted = false;
            $this->posted_at = null;
            $this->save();
        });

        return $this;
    }

    protected static function generateEntryNumber(): string
    {
        $year = date('Y');
        $lastEntry = static::where('entry_number', 'like', "JE-{$year}-%")
            ->orderBy('entry_number', 'desc')
            ->first();

        if ($lastEntry) {
            $lastNumber = (int) substr((string) $lastEntry->entry_number, -6);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf('JE-%s-%06d', $year, $newNumber);
    }
}
