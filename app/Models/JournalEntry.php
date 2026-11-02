<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\Approvable;
use App\Contracts\ApprovableRecord;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            if ($journalEntry->exists && static::query()->whereKey($journalEntry->getKey())->where('is_posted', true)->exists()) {
                throw new \DomainException('Posted journal entries are immutable; create a reversal instead.');
            }
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

        static::deleting(function (JournalEntry $journalEntry): void {
            if (static::query()->whereKey($journalEntry->getKey())->where('is_posted', true)->exists()) {
                throw new \DomainException('Posted journal entries are immutable; create a reversal instead.');
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

    /** @return HasOne<JournalEntry, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
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
        DB::transaction(function (): void {
            $entry = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($entry->is_posted) {
                throw new \DomainException('Journal entry is already posted.');
            }

            // Read persisted rows after obtaining the parent lock. Cached model
            // relations and stale callers must never decide financial effects.
            // Database line guards acquire this same parent lock. Avoid taking
            // child locks here: SQL UPDATE takes its child lock before its
            // trigger runs, so reversing that order would introduce deadlocks.
            $lines = $entry->lines()->orderBy('id')->get();
            if (! $entry->isBalanced()) {
                throw new \DomainException('Journal entry must be balanced before posting.');
            }

            $team = Team::query()->whereKey($entry->team_id)->lockForUpdate()->first();
            if (! $team instanceof Team) {
                throw new \DomainException('Journal entry must belong to a posting team.');
            }
            $entry->setRelation('team', $team);

            // A consistent account-lock order prevents lost updates between
            // different journals that share accounts, including repeated lines.
            $accounts = Account::query()->whereIn('id', $lines->pluck('account_id')->unique())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $account = $accounts->get($line->account_id);
                if (! $account instanceof Account || (string) $account->team_id !== (string) $entry->team_id) {
                    throw new \DomainException('Journal accounts must belong to the posting team.');
                }
                if (! in_array($account->normal_balance, ['debit', 'credit'], true)) {
                    throw new \DomainException('Journal accounts must have a valid normal balance.');
                }
            }

            foreach ($lines as $line) {
                $account = $accounts->get($line->account_id);
                $change = $account->normal_balance === 'debit'
                    ? bcsub($line->debit_amount, $line->credit_amount, 2)
                    : bcsub($line->credit_amount, $line->debit_amount, 2);
                $account->balance = bcadd($account->balance, $change, 2);
                $account->save();
            }

            $entry->forceFill(['is_posted' => true, 'posted_at' => now()])->save();
        });

        return $this->refresh();
    }

    public function reverse(?string $entryDate = null): static
    {
        DB::transaction(function () use ($entryDate): void {
            $entry = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if (! $entry->is_posted) {
                throw new \DomainException('Cannot reverse an unposted journal entry.');
            }
            if ($entry->reversal()->exists()) {
                throw new \DomainException('Journal entry is already reversed.');
            }

            $date = $entryDate ?? today()->toDateString();
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
                || Carbon::parse($date)->lt($entry->entry_date)) {
                throw new \DomainException('Reversal date must be a valid date on or after the original entry.');
            }

            $reversal = $entry->newInstance();
            $reversal->forceFill([
                'entry_number' => 'REV-'.$entry->getKey(),
                'entry_date' => $date,
                'entry_type' => 'reversing',
                'reference_number' => $entry->entry_number,
                'memo' => 'Reversal of '.$entry->entry_number,
                'team_id' => $entry->team_id,
                'user_id' => auth()->id() ?? $entry->user_id,
                'reversal_of_id' => $entry->getKey(),
            ])->save();

            foreach ($entry->lines()->orderBy('id')->get() as $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'debit_amount' => $line->credit_amount,
                    'credit_amount' => $line->debit_amount,
                    'description' => $line->description,
                    'department' => $line->department,
                    'cost_center' => $line->cost_center,
                ]);
            }

            $reversal->post();
        });

        return $this->refresh();
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
