<?php

declare(strict_types=1);

use App\Models\Team;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\FinancialStatements\Exceptions\InvalidStatementRequest;
use Liberu\Accounting\FinancialStatements\Queries\StatementQuery;
use Liberu\Accounting\GeneralLedger\Actions\CreateJournal;
use Liberu\Accounting\GeneralLedger\Actions\PostJournal;
use Liberu\Accounting\GeneralLedger\Actions\ReverseJournal;
use Liberu\Accounting\GeneralLedger\Enums\JournalStatus;
use Liberu\Accounting\GeneralLedger\Exceptions\InvalidJournal;
use Liberu\Accounting\GeneralLedger\Models\JournalEntry;
use Liberu\Accounting\GeneralLedger\Queries\AccountBalances;
use Liberu\Accounting\Periods\Exceptions\InvalidPeriodTransition;
use Liberu\Accounting\Periods\Models\AccountingPeriod;

beforeEach(function (): void {
    $entity = LegalEntity::create(['name' => 'Synthetic integrity company', 'currency_code' => 'USD']);
    $this->book = Book::create(['legal_entity_id' => $entity->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->cash = Account::create(['legal_entity_id' => $entity->id, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit']);
    $this->revenue = Account::create(['legal_entity_id' => $entity->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit']);
    $this->journal = app(CreateJournal::class)->handle(['book_id' => $this->book->id, 'entry_date' => '2026-01-15'], [
        ['account_id' => $this->cash->id, 'debit' => '100.00'],
        ['account_id' => $this->revenue->id, 'credit' => '100.00'],
    ]);
});

it('rejects amounts that would be silently rounded or lose a cent in float arithmetic', function (string $debit, string $credit): void {
    expect(fn () => app(CreateJournal::class)->handle(['book_id' => $this->book->id, 'entry_date' => '2026-01-16'], [
        ['account_id' => $this->cash->id, 'debit' => $debit],
        ['account_id' => $this->revenue->id, 'credit' => $credit],
    ]))->toThrow(InvalidJournal::class);
    expect(JournalEntry::count())->toBe(1);
})->with([
    'fractional cent' => ['0.004', '0.004'],
    'large amount differs by a cent' => ['10000000000000001.01', '10000000000000001.00'],
    'negative amount' => ['-1.00', '-1.00'],
    'scientific notation' => ['1e2', '100.00'],
]);

it('rolls back balanced amounts that SQLite cannot store without losing cents', function (): void {
    expect(fn () => app(CreateJournal::class)->handle(['book_id' => $this->book->id, 'entry_date' => '2026-01-16'], [
        ['account_id' => $this->cash->id, 'debit' => '10000000000000001.01'],
        ['account_id' => $this->revenue->id, 'credit' => '10000000000000001.01'],
    ]))->toThrow(InvalidJournal::class);
    expect(JournalEntry::count())->toBe(1);
});

it('requires configured periods unless non-production setup mode is explicit', function (): void {
    config()->set('accounting-periods.setup_mode', false);
    expect(fn () => app(PostJournal::class)->handle($this->journal))->toThrow(InvalidPeriodTransition::class);
    expect($this->journal->fresh()->status)->toBe(JournalStatus::Draft);
    config()->set('accounting-periods.setup_mode', true);
    expect(app(PostJournal::class)->handle($this->journal)->status)->toBe(JournalStatus::Posted);
});

it('never allows unconfigured periods in production even with setup enabled', function (): void {
    config()->set('accounting-periods.setup_mode', true);
    app()->detectEnvironment(fn (): string => 'production');
    expect(fn () => app(PostJournal::class)->handle($this->journal))->toThrow(InvalidPeriodTransition::class);
});

it('revalidates individual draft line amounts before posting', function (string $debit, string $credit): void {
    $this->journal->lines()->first()->update(['debit' => $debit, 'credit' => $credit]);
    expect(fn () => app(PostJournal::class)->handle($this->journal))->toThrow(InvalidJournal::class);
    expect($this->journal->fresh()->status)->toBe(JournalStatus::Draft);
})->with([
    'both sides positive' => ['200.00', '100.00'],
    'negative debit' => ['-100.00', '0.00'],
    'empty line' => ['0.00', '0.00'],
]);

it('rejects caller supplied posting state when creating a journal', function (): void {
    expect(fn () => app(CreateJournal::class)->handle([
        'book_id' => $this->book->id, 'entry_date' => '2026-01-16', 'status' => 'posted',
    ], [
        ['account_id' => $this->cash->id, 'debit' => '100.00'],
        ['account_id' => $this->revenue->id, 'credit' => '100.00'],
    ]))->toThrow(InvalidJournal::class);
});

it('blocks new lines on posted journals', function (): void {
    $posted = app(PostJournal::class)->handle($this->journal);
    expect(fn () => $posted->lines()->create(['account_id' => $this->cash->id, 'debit' => '1.00']))
        ->toThrow(LogicException::class);
    expect($posted->lines()->count())->toBe(2);
});

it('rejects caller supplied posting metadata on a draft journal', function (): void {
    expect(fn () => app(CreateJournal::class)->handle([
        'book_id' => $this->book->id, 'entry_date' => '2026-01-16', 'posted_at' => '2026-01-16 12:00:00',
    ], [
        ['account_id' => $this->cash->id, 'debit' => '100.00'],
        ['account_id' => $this->revenue->id, 'credit' => '100.00'],
    ]))->toThrow(InvalidJournal::class);
});

it('blocks moving a posted line into a draft journal', function (): void {
    $posted = app(PostJournal::class)->handle($this->journal);
    $draft = app(CreateJournal::class)->handle(['book_id' => $this->book->id, 'entry_date' => '2026-01-16'], [
        ['account_id' => $this->cash->id, 'debit' => '1.00'],
        ['account_id' => $this->revenue->id, 'credit' => '1.00'],
    ]);
    $line = $posted->lines()->firstOrFail();
    expect(fn () => $line->update(['journal_entry_id' => $draft->id]))->toThrow(LogicException::class);
    expect($line->fresh()->journal_entry_id)->toBe($posted->id);
});

it('rejects direct draft line updates to an account in another entity', function (): void {
    $otherEntity = LegalEntity::create(['name' => 'Other synthetic company', 'currency_code' => 'USD']);
    $otherAccount = Account::create(['legal_entity_id' => $otherEntity->id, 'code' => '1000', 'name' => 'Other cash', 'type' => 'asset', 'normal_balance' => 'debit']);
    $line = $this->journal->lines()->firstOrFail();
    expect(fn () => $line->update(['account_id' => $otherAccount->id]))->toThrow(LogicException::class);
    expect($line->fresh()->account_id)->toBe($this->cash->id);
});

it('rechecks account ownership at posting time', function (): void {
    $otherEntity = LegalEntity::create(['name' => 'Other synthetic company', 'currency_code' => 'USD']);
    $this->cash->update(['legal_entity_id' => $otherEntity->id]);
    expect(fn () => app(PostJournal::class)->handle($this->journal))->toThrow(InvalidJournal::class);
    expect($this->journal->fresh()->status)->toBe(JournalStatus::Draft);
});

it('keeps original postings in historical reports after an opposite reversal', function (): void {
    $posted = app(PostJournal::class)->handle($this->journal, 'synthetic-reviewer');
    $reversal = app(ReverseJournal::class)->handle($posted, ['entry_date' => '2026-02-01']);
    $balances = app(AccountBalances::class);
    $statements = app(StatementQuery::class);

    expect($posted->fresh()->posted_at)->not->toBeNull()
        ->and($reversal->reversal_of_id)->toBe($posted->id)
        ->and($balances->handle($this->book->id, '2026-01-31')[0]['balance'])->toBe('100.00')
        ->and(collect($balances->handle($this->book->id, '2026-02-28'))->pluck('balance')->all())->toBe(['0.00', '0.00'])
        ->and($statements->profitAndLoss($this->book->id, '2026-01-01', '2026-01-31')['net_income'])->toBe(100.0)
        ->and($statements->balanceSheet($this->book->id, '2026-02-28')['balance_check'])->toBe(0.0)
        ->and($statements->drillThrough($this->book->id, $this->cash->id, '2026-01-01', '2026-02-28'))->toHaveCount(2);
});

it('keeps reversals in the original book despite conflicting caller attributes', function (): void {
    $otherBook = Book::create(['legal_entity_id' => $this->book->legal_entity_id, 'name' => 'Other book', 'code' => 'OTHER']);
    $posted = app(PostJournal::class)->handle($this->journal);
    $reversal = app(ReverseJournal::class)->handle($posted, ['book_id' => $otherBook->id, 'reversal_of_id' => null, 'journal_type' => 'general']);
    expect($reversal->book_id)->toBe($this->book->id)
        ->and($reversal->reversal_of_id)->toBe($posted->id)
        ->and($reversal->journal_type->value)->toBe('reversal');
});

it('keeps encrypted integration settings out of team serialization', function (): void {
    $team = new Team();
    $team->forceFill(['name' => 'Synthetic secret company', 'vonage_key' => 'synthetic-key', 'vonage_secret' => 'synthetic-secret', 'accounting_setup' => ['integration_token' => 'synthetic-token']]);
    expect($team->toArray())->not->toHaveKeys(['vonage_key', 'vonage_secret', 'accounting_setup']);
});

it('reconciles beginning cash and period movements without including noncash assets', function (): void {
    $this->cash->update(['metadata' => ['is_cash_equivalent' => true]]);
    app(PostJournal::class)->handle($this->journal);
    $inventory = Account::create(['legal_entity_id' => $this->book->legal_entity_id, 'code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'normal_balance' => 'debit']);
    foreach ([[$this->cash->id, '25.00'], [$inventory->id, '500.00']] as [$accountId, $amount]) {
        app(PostJournal::class)->handle(app(CreateJournal::class)->handle(['book_id' => $this->book->id, 'entry_date' => '2026-02-15'], [
            ['account_id' => $accountId, 'debit' => $amount],
            ['account_id' => $this->revenue->id, 'credit' => $amount],
        ]));
    }
    $report = app(StatementQuery::class)->cashFlow($this->book->id, '2026-02-01', '2026-02-28');
    expect($report['beginning_cash'])->toBe(100.0)
        ->and($report['ending_cash'])->toBe(125.0)
        ->and($report['net_change_in_cash'])->toBe(25.0)
        ->and($report['classification_complete'])->toBeFalse()
        ->and($report['operating_activities']['net_cash_from_operations'])->toBeNull()
        ->and($report['unclassified_activities']['net_cash_unclassified'])->toBe(25.0)
        ->and($report['unclassified_activities']['accounts'])->toHaveCount(1);
});

it('does not guess that an unclassified asset account is cash', function (): void {
    app(PostJournal::class)->handle($this->journal);
    $report = app(StatementQuery::class)->cashFlow($this->book->id, '2026-01-01', '2026-01-31');
    expect($report['ending_cash'])->toBe(0.0)
        ->and($report['unclassified_activities']['accounts'])->toBeEmpty();
});

it('preserves negative expense balances instead of turning refunds into expenses', function (): void {
    $expense = Account::create(['legal_entity_id' => $this->book->legal_entity_id, 'code' => '5000', 'name' => 'Refunded expense', 'type' => 'expense', 'normal_balance' => 'debit']);
    app(PostJournal::class)->handle(app(CreateJournal::class)->handle(['book_id' => $this->book->id, 'entry_date' => '2026-01-20'], [
        ['account_id' => $this->cash->id, 'debit' => '20.00'],
        ['account_id' => $expense->id, 'credit' => '20.00'],
    ]));
    $report = app(StatementQuery::class)->profitAndLoss($this->book->id, '2026-01-01', '2026-01-31');
    expect($report['expenses']['total'])->toBe(-20.0)->and($report['net_income'])->toBe(20.0);
});

it('preserves negative liabilities in the accounting equation', function (): void {
    $liability = Account::create(['legal_entity_id' => $this->book->legal_entity_id, 'code' => '2000', 'name' => 'Overpaid liability', 'type' => 'liability', 'normal_balance' => 'credit']);
    app(PostJournal::class)->handle(app(CreateJournal::class)->handle(['book_id' => $this->book->id, 'entry_date' => '2026-01-20'], [
        ['account_id' => $liability->id, 'debit' => '20.00'],
        ['account_id' => $this->cash->id, 'credit' => '20.00'],
    ]));
    $report = app(StatementQuery::class)->balanceSheet($this->book->id, '2026-01-31');
    expect($report['assets']['total'])->toBe(-20.0)
        ->and($report['liabilities']['total'])->toBe(-20.0)->and($report['balance_check'])->toBe(0.0);
});

it('deducts contra assets even when their configured normal balance is credit', function (): void {
    app(PostJournal::class)->handle($this->journal);
    $contra = Account::create(['legal_entity_id' => $this->book->legal_entity_id, 'code' => '1500', 'name' => 'Accumulated depreciation', 'type' => 'asset', 'normal_balance' => 'credit']);
    $expense = Account::create(['legal_entity_id' => $this->book->legal_entity_id, 'code' => '5000', 'name' => 'Depreciation', 'type' => 'expense', 'normal_balance' => 'debit']);
    app(PostJournal::class)->handle(app(CreateJournal::class)->handle(['book_id' => $this->book->id, 'entry_date' => '2026-01-20'], [
        ['account_id' => $expense->id, 'debit' => '20.00'],
        ['account_id' => $contra->id, 'credit' => '20.00'],
    ]));
    $report = app(StatementQuery::class)->balanceSheet($this->book->id, '2026-01-31');
    expect($report['assets']['total'])->toBe(80.0)
        ->and($report['equity']['total'])->toBe(80.0)->and($report['balance_check'])->toBe(0.0);
});

it('includes retained earnings posted before the year 2000', function (): void {
    $this->journal->update(['entry_date' => '1999-01-15']);
    app(PostJournal::class)->handle($this->journal);
    $report = app(StatementQuery::class)->balanceSheet($this->book->id, '1999-12-31');
    expect($report['equity']['retained_earnings'])->toBe(100.0)->and($report['balance_check'])->toBe(0.0);
});

it('rejects posting into closed periods through the public GL action', function (string $state): void {
    AccountingPeriod::create(['book_id' => $this->book->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'state' => $state]);
    expect(fn () => app(PostJournal::class)->handle($this->journal))->toThrow(InvalidPeriodTransition::class);
    expect($this->journal->fresh()->status)->toBe(JournalStatus::Draft);
})->with(['soft_closed', 'hard_closed']);

it('rejects posting after the configured posting cutoff', function (): void {
    AccountingPeriod::create(['book_id' => $this->book->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'posting_ends_on' => '2026-01-14', 'state' => 'open']);
    expect(fn () => app(PostJournal::class)->handle($this->journal))->toThrow(InvalidPeriodTransition::class);
});

it('rejects dates outside an existing fiscal calendar and ambiguous overlapping periods', function (): void {
    AccountingPeriod::create(['book_id' => $this->book->id, 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28', 'state' => 'open']);
    expect(fn () => app(PostJournal::class)->handle($this->journal))->toThrow(InvalidPeriodTransition::class);
    AccountingPeriod::create(['book_id' => $this->book->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'state' => 'open']);
    AccountingPeriod::create(['book_id' => $this->book->id, 'starts_on' => '2026-01-10', 'ends_on' => '2026-01-20', 'state' => 'open']);
    expect(fn () => app(PostJournal::class)->handle($this->journal))->toThrow(InvalidPeriodTransition::class);
});

it('applies the same period protection to reversal journals atomically', function (): void {
    $posted = app(PostJournal::class)->handle($this->journal);
    AccountingPeriod::create(['book_id' => $this->book->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'state' => 'open']);
    AccountingPeriod::create(['book_id' => $this->book->id, 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28', 'state' => 'hard_closed']);
    expect(fn () => app(ReverseJournal::class)->handle($posted, ['entry_date' => '2026-02-01']))->toThrow(InvalidPeriodTransition::class);
    expect($posted->fresh()->status)->toBe(JournalStatus::Posted)->and(JournalEntry::count())->toBe(1);
});

it('allows posting into an open period despite a different book being closed', function (): void {
    $otherBook = Book::create(['legal_entity_id' => $this->book->legal_entity_id, 'name' => 'Other book', 'code' => 'OTHER']);
    AccountingPeriod::create(['book_id' => $otherBook->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'state' => 'hard_closed']);
    AccountingPeriod::create(['book_id' => $this->book->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'state' => 'open']);
    expect(app(PostJournal::class)->handle($this->journal)->status)->toBe(JournalStatus::Posted);
});

it('rejects a locked period even if its state was changed to open', function (): void {
    AccountingPeriod::create(['book_id' => $this->book->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'state' => 'open', 'locked_at' => now()]);
    expect(fn () => app(PostJournal::class)->handle($this->journal))->toThrow(InvalidPeriodTransition::class);
});

it('keeps public statement dates strict while normalizing stored posting dates', function (): void {
    app(PostJournal::class)->handle($this->journal);
    $query = app(StatementQuery::class);
    expect($query->balanceSheet($this->book->id, '2026-01-31')['balance_check'])->toBe(0.0);
    expect(fn () => $query->balanceSheet($this->book->id, '2026-01-31 00:00:00'))->toThrow(InvalidStatementRequest::class);
});
