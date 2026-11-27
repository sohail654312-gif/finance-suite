<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\ReconciliationRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReconciliationService;
use App\Services\TeamManagementService;

beforeEach(function (): void {
    $this->precisionTeam = app(TeamManagementService::class)->createPersonalTeamForUser(User::factory()->create());
    $this->precisionAccount = Account::factory()->create(['team_id' => $this->precisionTeam->getKey()]);
    $this->precisionStatement = BankStatement::factory()->create([
        'team_id' => $this->precisionTeam->getKey(),
        'account_id' => $this->precisionAccount->getKey(),
        'statement_date' => '2026-11-15',
        'total_credits' => '0.30',
        'total_debits' => '0.10',
        'ending_balance' => '0.20',
        'reconciled' => false,
    ]);
});

it('reconciles fractional credits and debits without a phantom balance mismatch', function (): void {
    foreach (['0.10', '0.20', '-0.10', '0.00'] as $amount) {
        Transaction::factory()->create([
            'team_id' => $this->precisionTeam->getKey(),
            'account_id' => $this->precisionAccount->getKey(),
            'bank_statement_id' => $this->precisionStatement->getKey(),
            'transaction_date' => '2026-11-15',
            'amount' => $amount,
        ]);
    }

    $result = app(ReconciliationService::class)->reconcileStatement($this->precisionStatement);

    expect($result['total_credits'])->toBe('0.30')
        ->and($result['total_debits'])->toBe('0.10')
        ->and($result['balance_discrepancy'])->toBe('0.00')
        ->and($result['discrepancies'])->toBeEmpty()
        ->and($result['matched_transactions'])->toBe(4)
        ->and($result['reconciled'])->toBeTrue()
        ->and($this->precisionStatement->fresh()->reconciled)->toBeTrue();
});

it('keeps a statement open for either direction of a one cent discrepancy', function (string $statementCredit, string $discrepancy): void {
    $this->precisionStatement->update(['total_credits' => $statementCredit, 'total_debits' => '0.00']);
    foreach (['0.10', '0.20'] as $amount) {
        Transaction::factory()->create([
            'team_id' => $this->precisionTeam->getKey(),
            'account_id' => $this->precisionAccount->getKey(),
            'bank_statement_id' => $this->precisionStatement->getKey(),
            'transaction_date' => '2026-11-15',
            'amount' => $amount,
        ]);
    }

    $result = app(ReconciliationService::class)->reconcileStatement($this->precisionStatement);
    $mismatch = $result['discrepancies']->sole();

    expect($result['balance_discrepancy'])->toBe($discrepancy)
        ->and($result['unmatched_transactions'])->toBe(0)
        ->and($result['reconciled'])->toBeFalse()
        ->and($mismatch['type'])->toBe('balance_mismatch')
        ->and($mismatch['amount'])->toBe($discrepancy)
        ->and($mismatch['actual'])->toBe('0.30')
        ->and($this->precisionStatement->fresh()->reconciled)->toBeFalse();
})->with([
    'one cent short on the statement' => ['0.29', '0.01'],
    'one cent extra on the statement' => ['0.31', '-0.01'],
]);

it('retains cents when totals exceed an individual transaction column limit', function (): void {
    $this->precisionStatement->update([
        'total_credits' => '19999999999.98',
        'total_debits' => '9999999999.97',
        'ending_balance' => '10000000000.01',
    ]);
    foreach (['9999999999.99', '9999999999.99', '-9999999999.97'] as $amount) {
        Transaction::factory()->create([
            'team_id' => $this->precisionTeam->getKey(),
            'account_id' => $this->precisionAccount->getKey(),
            'bank_statement_id' => $this->precisionStatement->getKey(),
            'transaction_date' => '2026-11-15',
            'amount' => $amount,
        ]);
    }

    $result = app(ReconciliationService::class)->preview($this->precisionStatement);

    expect($result['total_credits'])->toBe('19999999999.98')
        ->and($result['total_debits'])->toBe('9999999999.97')
        ->and($result['balance_discrepancy'])->toBe('0.00')
        ->and($result['discrepancies'])->toBeEmpty();
});

it('compares amount rule bounds exactly without rounding malformed or fractional thresholds', function (
    string $operator,
    string $amount,
    string $lower,
    ?string $upper,
    bool $matches,
): void {
    $transaction = Transaction::factory()->create([
        'team_id' => $this->precisionTeam->getKey(),
        'account_id' => $this->precisionAccount->getKey(),
        'bank_statement_id' => null,
        'transaction_date' => '2026-11-15',
        'amount' => $amount,
        'reconciled' => false,
    ]);
    ReconciliationRule::factory()->create([
        'team_id' => $this->precisionTeam->getKey(),
        'match_field' => 'amount',
        'match_operator' => $operator,
        'match_value' => $lower,
        'match_value_secondary' => $upper,
    ]);

    $result = app(ReconciliationService::class)->reconcile($this->precisionStatement);

    expect($result['matched_transactions'])->toBe($matches ? 1 : 0)
        ->and($result['unmatched_transactions'])->toBe($matches ? 0 : 1)
        ->and($transaction->fresh()->reconciled)->toBe($matches);
})->with([
    'equal cents' => ['equals', '0.10', '0.10', null, true],
    'trailing decimal zeroes' => ['equals', '0.10', '0.1000', null, true],
    'signed fractional decimal' => ['equals', '0.10', '+.10', null, true],
    'negative cents' => ['equals', '-0.10', '-0.10', null, true],
    'sub-cent above the amount' => ['equals', '0.10', '0.1001', null, false],
    'sub-cent below the amount' => ['equals', '0.10', '0.0999', null, false],
    'adjacent cent' => ['equals', '0.10', '0.11', null, false],
    'malformed zero is not zero' => ['equals', '0.00', 'invalid', null, false],
    'scientific notation is not a decimal rule' => ['equals', '0.10', '1e-1', null, false],
    'inclusive lower bound' => ['between', '0.10', '0.10', '0.20', true],
    'inclusive upper bound' => ['between', '0.20', '0.10', '0.20', true],
    'negative interval' => ['between', '-0.10', '-0.20', '-0.10', true],
    'sub-cent lower boundary' => ['between', '0.10', '0.1001', '0.20', false],
    'sub-cent upper boundary' => ['between', '0.10', '0.00', '0.0999', false],
    'reversed interval' => ['between', '0.15', '0.20', '0.10', false],
    'invalid lower boundary' => ['between', '0.00', 'invalid', '0.10', false],
    'missing upper boundary' => ['between', '0.00', '-0.10', null, false],
]);

it('ignores foreign and inactive rules while allowing an owned account action', function (): void {
    $foreignTeam = app(TeamManagementService::class)->createPersonalTeamForUser(User::factory()->create());
    $category = Account::factory()->create(['team_id' => $this->precisionTeam->getKey()]);
    $foreignCategory = Account::factory()->create(['team_id' => $foreignTeam->getKey()]);
    $fields = ['match_field' => 'amount', 'match_operator' => 'equals', 'match_value' => '0.10'];
    ReconciliationRule::factory()->create($fields + [
        'team_id' => $foreignTeam->getKey(), 'action_account_id' => $foreignCategory->getKey(), 'priority' => 0,
    ]);
    ReconciliationRule::factory()->create($fields + [
        'team_id' => $this->precisionTeam->getKey(), 'is_active' => false,
        'action_account_id' => $foreignCategory->getKey(), 'priority' => 1,
    ]);
    ReconciliationRule::factory()->create($fields + [
        'team_id' => $this->precisionTeam->getKey(), 'action_account_id' => $category->getKey(), 'priority' => 2,
    ]);
    $transaction = Transaction::factory()->create([
        'team_id' => $this->precisionTeam->getKey(), 'account_id' => $this->precisionAccount->getKey(),
        'bank_statement_id' => null, 'transaction_date' => '2026-11-15', 'amount' => '0.10',
        'debit_account_id' => null, 'reconciled' => false,
    ]);

    $result = app(ReconciliationService::class)->reconcile($this->precisionStatement);

    expect($result['matched_transactions'])->toBe(1)
        ->and($transaction->fresh()->debit_account_id)->toBe($category->getKey())
        ->and($transaction->fresh()->reconciled)->toBeTrue();
});

it('rejects a matched foreign action and rolls back reconciliation writes', function (string $method): void {
    $foreignTeam = app(TeamManagementService::class)->createPersonalTeamForUser(User::factory()->create());
    $foreignCategory = Account::factory()->create(['team_id' => $foreignTeam->getKey()]);
    $category = Account::factory()->create(['team_id' => $this->precisionTeam->getKey()]);
    $transactions = collect();
    foreach (['0.10' => $category, '0.20' => $foreignCategory] as $amount => $actionAccount) {
        $transactions->push(Transaction::factory()->create([
            'team_id' => $this->precisionTeam->getKey(), 'account_id' => $this->precisionAccount->getKey(),
            'bank_statement_id' => null, 'transaction_date' => '2026-11-15', 'amount' => $amount,
            'debit_account_id' => null, 'reconciled' => false,
        ]));
        ReconciliationRule::factory()->create([
            'team_id' => $this->precisionTeam->getKey(), 'match_field' => 'amount',
            'match_operator' => 'equals', 'match_value' => $amount, 'action_account_id' => $actionAccount->getKey(),
        ]);
    }

    expect(fn () => app(ReconciliationService::class)->{$method}($this->precisionStatement))
        ->toThrow(DomainException::class, 'Reconciliation rule action account must belong to the statement team.');

    foreach ($transactions as $transaction) {
        expect($transaction->fresh()->reconciled)->toBeFalse()
            ->and($transaction->fresh()->debit_account_id)->toBeNull();
    }
    expect($this->precisionStatement->fresh()->reconciled)->toBeFalse();
})->with(['preview', 'reconcile', 'reconcileStatement']);

it('previews exact rule matches without mutating dates or persisted state', function (): void {
    $category = Account::factory()->create(['team_id' => $this->precisionTeam->getKey()]);
    $transaction = Transaction::factory()->create([
        'team_id' => $this->precisionTeam->getKey(), 'account_id' => $this->precisionAccount->getKey(),
        'bank_statement_id' => null, 'transaction_date' => '2026-11-15', 'amount' => '0.10',
        'debit_account_id' => null, 'reconciled' => false,
    ]);
    ReconciliationRule::factory()->create([
        'team_id' => $this->precisionTeam->getKey(), 'match_field' => 'amount', 'match_operator' => 'equals',
        'match_value' => '0.10', 'action_account_id' => $category->getKey(),
    ]);
    $statementAttributes = $this->precisionStatement->getAttributes();

    $result = app(ReconciliationService::class)->preview($this->precisionStatement);

    expect($result['matched_transactions'])->toBe(1)
        ->and($this->precisionStatement->getAttributes())->toBe($statementAttributes)
        ->and($this->precisionStatement->statement_date->format('Y-m-d'))->toBe('2026-11-15')
        ->and($this->precisionStatement->fresh()->reconciled)->toBeFalse()
        ->and($transaction->fresh()->reconciled)->toBeFalse()
        ->and($transaction->fresh()->debit_account_id)->toBeNull();
});
