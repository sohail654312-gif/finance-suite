<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\FinancialStatementsFilament\Pages\FinancialStatements;
use Liberu\Accounting\FinancialStatementsLivewire\Livewire\Statements;
use Liberu\Accounting\GeneralLedger\Actions\PostJournal;
use Liberu\Accounting\GeneralLedger\Actions\ReverseJournal;
use Liberu\Accounting\GeneralLedger\Enums\JournalStatus;
use Liberu\Accounting\GeneralLedger\Exceptions\InvalidJournal;
use Liberu\Accounting\GeneralLedger\Models\JournalEntry;
use Liberu\Accounting\GeneralLedger\Queries\AccountBalances;
use Liberu\Accounting\Periods\Actions\CreatePeriod;
use Liberu\Accounting\Periods\Actions\TransitionPeriod;
use Liberu\Accounting\Periods\Enums\PeriodState;
use Liberu\Accounting\Periods\Exceptions\InvalidPeriodTransition;
use Livewire\Livewire;

function financialLifecycleOwner(): User
{
    $user = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($user);

    return $user->refresh();
}

function financialLifecycleAbilities(): array
{
    return ['accounting.core.read', 'accounting.core.write', 'accounting.chart.read', 'accounting.chart.write',
        'accounting.general-ledger.read', 'accounting.general-ledger.write', 'accounting.financial-statements.read'];
}

it('reconciles an owned synthetic ledger from onboarding through statements period close and next-period reversal', function (): void {
    $owner = financialLifecycleOwner();
    $stranger = financialLifecycleOwner();
    Sanctum::actingAs($owner, financialLifecycleAbilities());
    $this->travelTo(new DateTimeImmutable('2026-02-01 12:00:00'));

    $entityId = $this->postJson('/api/v1/accounting/accounting-core/legal-entities', [
        'name' => 'Lifecycle Tenant A', 'currency_code' => 'USD', 'accounting_basis' => 'accrual',
    ])->assertCreated()->json('data.id');
    $bookId = (int) $this->postJson('/api/v1/accounting/accounting-core/books', [
        'legal_entity_id' => $entityId, 'name' => 'Lifecycle USD', 'code' => 'LIFE', 'accounting_basis' => 'accrual',
    ])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/accounting/accounting-core/books/{$bookId}/fiscal-calendars", [
        'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
    ])->assertCreated();
    $january = app(CreatePeriod::class)->handle(['book_id' => $bookId, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31']);
    app(CreatePeriod::class)->handle(['book_id' => $bookId, 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28']);

    $accounts = [];
    foreach ([['cash', '1000', 'asset', 'debit'], ['receivable', '1100', 'asset', 'debit'],
        ['payable', '2000', 'liability', 'credit'], ['accrued', '2100', 'liability', 'credit'],
        ['capital', '3000', 'equity', 'credit'], ['revenue', '4000', 'revenue', 'credit'],
        ['expense', '5000', 'expense', 'debit']] as [$name, $code, $type, $normal]) {
        $accounts[$name] = (int) $this->postJson('/api/v1/accounting/chart-of-accounts/accounts', [
            'legal_entity_id' => $entityId, 'code' => $code, 'name' => $name, 'type' => $type,
            'normal_balance' => $normal, 'metadata' => ['is_cash_equivalent' => $name === 'cash'],
        ])->assertCreated()->json('data.id');
    }

    $create = function (string $number, string $debit, string $credit, string $amount, string $type = 'general') use ($bookId, $accounts): JournalEntry {
        $id = $this->postJson('/api/v1/accounting/general-ledger', [
            'book_id' => $bookId, 'entry_number' => $number, 'entry_date' => '2026-01-15', 'journal_type' => $type,
            'lines' => [['account_id' => $accounts[$debit], 'debit' => $amount], ['account_id' => $accounts[$credit], 'credit' => $amount]],
        ])->assertCreated()->json('data.id');

        return JournalEntry::query()->findOrFail($id);
    };
    $posted = [];
    foreach ([['CAPITAL', 'cash', 'capital', '1000.00'], ['SALE-GL', 'receivable', 'revenue', '240.25'],
        ['RECEIPT-GL', 'cash', 'receivable', '100.10'], ['BILL-GL', 'expense', 'payable', '60.15'],
        ['PAYMENT-GL', 'payable', 'cash', '40.00'], ['ACCRUAL', 'expense', 'accrued', '25.05', 'accrual']] as $fixture) {
        $journal = $create(...$fixture);
        $this->postJson('/api/v1/accounting/general-ledger/'.$journal->id.'/post')->assertOk();
        $journal->refresh()->load('lines');
        expect($journal->totalDebits())->toBe($fixture[3])
            ->and($journal->totalCredits())->toBe($fixture[3])
            ->and((int) $journal->team_id)->toBe((int) $owner->current_team_id)
            ->and($journal->posted_by)->toBe((string) $owner->id)
            ->and($journal->posted_at)->not->toBeNull();
        $posted[] = $journal;
    }
    $draft = $create('DRAFT-EXCLUDED', 'cash', 'revenue', '99999.99');
    $balances = collect(app(AccountBalances::class)->handle($bookId, '2026-01-31'))->keyBy('account_id');
    expect($balances[$accounts['cash']]['balance'])->toBe('1060.10')
        ->and($balances[$accounts['receivable']]['balance'])->toBe('140.15')
        ->and($balances[$accounts['payable']]['balance'])->toBe('-20.15')
        ->and($balances->reduce(fn (string $sum, array $row): string => bcadd($sum, $row['debits'], 2), '0.00'))->toBe('1465.55')
        ->and($balances->reduce(fn (string $sum, array $row): string => bcadd($sum, $row['credits'], 2), '0.00'))->toBe('1465.55')
        ->and($balances->reduce(fn (string $sum, array $row): string => bcadd($sum, $row['balance'], 2), '0.00'))->toBe('0.00');

    $periodQuery = http_build_query(['book_id' => $bookId, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
    $this->getJson('/api/v1/accounting/financial-statements/profit-and-loss?'.$periodQuery)
        ->assertOk()->assertJsonPath('data.revenue.total', 240.25)->assertJsonPath('data.expenses.total', 85.2)->assertJsonPath('data.net_income', 155.05);
    $this->getJson('/api/v1/accounting/financial-statements/balance-sheet?book_id='.$bookId.'&as_of_date=2026-01-31')
        ->assertOk()->assertJsonPath('data.assets.total', 1200.25)->assertJsonPath('data.liabilities.total', 45.2)
        ->assertJsonPath('data.equity.total', 1155.05)->assertJsonPath('data.balance_check', 0);
    $this->getJson('/api/v1/accounting/financial-statements/cash-flow?'.$periodQuery)
        ->assertOk()->assertJsonPath('data.beginning_cash', 0)->assertJsonPath('data.ending_cash', 1060.1)
        ->assertJsonPath('data.net_change_in_cash', 1060.1)->assertJsonPath('data.classification_complete', false);

    Sanctum::actingAs($stranger, financialLifecycleAbilities());
    $this->getJson('/api/v1/accounting/general-ledger/'.$posted[0]->id)->assertForbidden();
    $this->postJson('/api/v1/accounting/general-ledger/'.$draft->id.'/post')->assertForbidden();
    $this->getJson('/api/v1/accounting/financial-statements/profit-and-loss?'.$periodQuery)->assertNotFound();
    expect($draft->fresh()->status)->toBe(JournalStatus::Draft);
    Sanctum::actingAs($owner, financialLifecycleAbilities());

    app(TransitionPeriod::class)->handle($january, PeriodState::SoftClosed, (string) $owner->id);
    expect(fn () => app(PostJournal::class)->handle($draft, (string) $owner->id))->toThrow(InvalidPeriodTransition::class);
    expect($draft->fresh()->status)->toBe(JournalStatus::Draft);

    $accrual = $posted[5];
    $originalPostedAt = $accrual->posted_at->toDateTimeString();
    $reversal = app(ReverseJournal::class)->handle($accrual, ['entry_date' => '2026-02-01'], (string) $owner->id);
    expect((int) $reversal->reversal_of_id)->toBe((int) $accrual->id)
        ->and($reversal->totalDebits())->toBe('25.05')->and($reversal->totalCredits())->toBe('25.05')
        ->and($accrual->fresh()->posted_at->toDateTimeString())->toBe($originalPostedAt);
    expect(fn () => app(ReverseJournal::class)->handle($accrual))->toThrow(InvalidJournal::class);
    $this->getJson('/api/v1/accounting/financial-statements/profit-and-loss?'.$periodQuery)
        ->assertOk()->assertJsonPath('data.net_income', 155.05);
    $this->getJson('/api/v1/accounting/financial-statements/balance-sheet?book_id='.$bookId.'&as_of_date=2026-02-28')
        ->assertOk()->assertJsonPath('data.assets.total', 1200.25)->assertJsonPath('data.liabilities.total', 20.15)
        ->assertJsonPath('data.equity.total', 1180.1)->assertJsonPath('data.balance_check', 0);
    $this->getJson('/api/v1/accounting/financial-statements/drill-through?'.$periodQuery.'&account_id='.$accounts['expense'])
        ->assertOk()->assertJsonCount(2, 'data');
    expect(JournalEntry::query()->where('book_id', $bookId)->whereIn('status', ['posted', 'reversed'])->count())->toBe(7);
});

it('denies every financial statement endpoint for another tenant book', function (string $report): void {
    $owner = financialLifecycleOwner();
    $other = financialLifecycleOwner();
    $entity = LegalEntity::create(['team_id' => $other->current_team_id, 'name' => 'Confidential Tenant B', 'currency_code' => 'USD']);
    $book = Book::create(['legal_entity_id' => $entity->id, 'name' => 'Confidential', 'code' => 'PRIVATE']);
    $account = Account::create(['legal_entity_id' => $entity->id, 'code' => '1000', 'name' => 'Confidential cash', 'type' => 'asset', 'normal_balance' => 'debit']);
    Sanctum::actingAs($owner, financialLifecycleAbilities());
    $parameters = ['book_id' => $book->id, 'account_id' => $account->id, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
        'as_of_date' => '2026-01-31', 'comparative_start_date' => '2025-01-01', 'comparative_end_date' => '2025-01-31'];
    $this->getJson('/api/v1/accounting/financial-statements/'.$report.'?'.http_build_query($parameters))->assertNotFound();
    Sanctum::actingAs($other, financialLifecycleAbilities());
    $this->getJson('/api/v1/accounting/financial-statements/'.$report.'?'.http_build_query($parameters))->assertOk();
})->with(['profit-and-loss', 'balance-sheet', 'cash-flow', 'changes-in-equity', 'comparative', 'drill-through']);

it('denies a report when the persisted selected team was revoked despite a cached authenticated identity', function (): void {
    $owner = financialLifecycleOwner();
    $entity = LegalEntity::create(['team_id' => $owner->current_team_id, 'name' => 'Former selection', 'currency_code' => 'USD']);
    $book = Book::create(['legal_entity_id' => $entity->id, 'name' => 'Former book', 'code' => 'FORMER']);
    $owner->load('currentTeam');
    Sanctum::actingAs($owner, financialLifecycleAbilities());
    User::query()->whereKey($owner->id)->update(['current_team_id' => null]);
    $this->getJson('/api/v1/accounting/financial-statements/balance-sheet?book_id='.$book->id.'&as_of_date=2026-01-31')->assertForbidden();
});

it('denies foreign report books in Filament and Livewire before rendering financial data', function (): void {
    $owner = financialLifecycleOwner();
    $other = financialLifecycleOwner();
    $entity = LegalEntity::create(['team_id' => $other->current_team_id, 'name' => 'UI Confidential', 'currency_code' => 'USD']);
    $book = Book::create(['legal_entity_id' => $entity->id, 'name' => 'UI Book', 'code' => 'UI']);
    Sanctum::actingAs($owner, financialLifecycleAbilities());
    expect(fn () => app(FinancialStatements::class)->mount((int) $book->id))->toThrow(ModelNotFoundException::class);
    Livewire::test(Statements::class, ['bookId' => $book->id, 'startDate' => '2026-01-01', 'endDate' => '2026-01-31'])->assertNotFound();
});

it('rechecks report ownership on Livewire updates after the selected team is revoked', function (): void {
    $owner = financialLifecycleOwner();
    $entity = LegalEntity::create(['team_id' => $owner->current_team_id, 'name' => 'UI Revoked', 'currency_code' => 'USD']);
    $book = Book::create(['legal_entity_id' => $entity->id, 'name' => 'UI Former Book', 'code' => 'REVOKED']);
    Sanctum::actingAs($owner, financialLifecycleAbilities());
    $component = Livewire::test(Statements::class, ['bookId' => $book->id, 'startDate' => '2026-01-01', 'endDate' => '2026-01-31'])
        ->assertSee('Net income: 0.00');
    User::query()->whereKey($owner->id)->update(['current_team_id' => null]);
    $component->set('statementType', 'balance_sheet')->assertForbidden();
});
