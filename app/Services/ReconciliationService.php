<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\ReconciliationRule;
use App\Models\Transaction;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    /**
     * Run reconciliation and flip the statement's reconciled flag based on the
     * outcome: reconciled only when the balance discrepancy is zero. Returns the
     * reconcile() result with a `reconciled` boolean added.
     */
    public function reconcileStatement(BankStatement $bankStatement): array
    {
        return DB::transaction(function () use ($bankStatement): array {
            $statement = BankStatement::query()->lockForUpdate()->findOrFail($bankStatement->getKey());
            $result = $this->reconcile($statement);

            $reconciled = (int) $result['unmatched_transactions'] === 0
                && bccomp($result['balance_discrepancy'], '0.00', 2) === 0;
            $statement->update(['reconciled' => $reconciled]);
            $bankStatement->refresh();

            $result['reconciled'] = $reconciled;

            return $result;
        });
    }

    public function preview(BankStatement $bankStatement): array
    {
        return $this->evaluate($bankStatement, persist: false);
    }

    public function reconcile(BankStatement $bankStatement): array
    {
        return DB::transaction(fn (): array => $this->evaluate($bankStatement, persist: true));
    }

    private function evaluate(BankStatement $bankStatement, bool $persist): array
    {
        $transactions = Transaction::where('account_id', $bankStatement->account_id)
            ->where('team_id', $bankStatement->team_id)
            ->whereBetween('transaction_date', [
                $bankStatement->statement_date->copy()->startOfMonth(),
                $bankStatement->statement_date->copy()->endOfMonth(),
            ])
            ->get();

        $totalCredits = '0.00';
        $totalDebits = '0.00';
        $matchedTransactions = collect();
        $unmatchedTransactions = collect();
        $discrepancies = collect();

        // Service layer: IsTenantModel is inert here, so scope rules to the
        // statement's team explicitly. Ordered by priority (lower first).
        $rules = ReconciliationRule::query()
            ->where('is_active', true)
            ->where('team_id', $bankStatement->team_id)
            ->orderBy('priority')
            ->get();

        foreach ($transactions as $transaction) {
            $amount = (string) $transaction->amount;
            if (bccomp($amount, '0.00', 2) > 0) {
                $totalCredits = bcadd($totalCredits, $amount, 2);
            } else {
                $totalDebits = bcsub($totalDebits, $amount, 2);
            }

            // User-defined rules take precedence; fall back to the built-in heuristic.
            $rule = $rules->first(fn (ReconciliationRule $rule): bool => $this->ruleMatches($rule, $transaction));
            if ($rule?->action_account_id !== null && ! Account::query()
                ->whereKey($rule->action_account_id)
                ->where('team_id', $bankStatement->team_id)
                ->exists()) {
                throw new DomainException('Reconciliation rule action account must belong to the statement team.');
            }
            $matched = $rule !== null || $this->findMatch($transaction, $bankStatement);

            if ($matched) {
                $matchedTransactions->push($transaction);
                if ($persist) {
                    if ($rule?->action_account_id !== null) {
                        $transaction->debit_account_id = $rule->action_account_id;
                    }
                    $transaction->update(['reconciled' => true]);
                }
            } else {
                $unmatchedTransactions->push($transaction);
                $discrepancies->push([
                    'type' => 'unmatched_transaction',
                    'date' => $transaction->transaction_date,
                    'amount' => $transaction->amount,
                ]);
            }
        }

        $actualBalance = bcsub($totalCredits, $totalDebits, 2);
        $statementBalance = bcsub((string) $bankStatement->total_credits, (string) $bankStatement->total_debits, 2);
        $balanceDiscrepancy = bcsub($actualBalance, $statementBalance, 2);

        if (bccomp($balanceDiscrepancy, '0.00', 2) !== 0) {
            $discrepancies->push([
                'type' => 'balance_mismatch',
                'amount' => $balanceDiscrepancy,
                'expected' => $bankStatement->ending_balance,
                'actual' => $actualBalance,
            ]);
        }

        return [
            'matched_transactions' => $matchedTransactions->count(),
            'unmatched_transactions' => $unmatchedTransactions->count(),
            'discrepancies' => $discrepancies,
            'total_credits' => $totalCredits,
            'total_debits' => $totalDebits,
            'balance_discrepancy' => $balanceDiscrepancy,
        ];
    }

    private function findMatch(Transaction $transaction, BankStatement $bankStatement): bool
    {
        // Try exact match first
        $exactMatch = $bankStatement->transactions()
            ->where('team_id', $bankStatement->team_id)
            ->where('transaction_date', $transaction->transaction_date)
            ->where('amount', $transaction->amount)
            ->exists();

        if ($exactMatch) {
            return true;
        }

        // Try fuzzy match within 2 days and exact amount
        $matchDate = Carbon::parse((string) $transaction->transaction_date);
        $fuzzyMatch = $bankStatement->transactions()
            ->where('team_id', $bankStatement->team_id)
            ->whereBetween('transaction_date', [
                $matchDate->copy()->subDays(2),
                $matchDate->copy()->addDays(2),
            ])
            ->where('amount', $transaction->amount)
            ->exists();

        return $fuzzyMatch;
    }

    private function ruleMatches(ReconciliationRule $rule, Transaction $transaction): bool
    {
        $field = match ($rule->match_field) {
            'amount' => (string) $transaction->amount,
            'reference' => (string) $transaction->external_id,
            default => (string) $transaction->description, // description
        };

        return match ($rule->match_operator) {
            'contains' => $rule->match_value !== ''
                && stripos($field, (string) $rule->match_value) !== false,
            'equals' => $rule->match_field === 'amount'
                ? $this->compareRuleAmount((string) $transaction->amount, (string) $rule->match_value) === 0
                : $field === (string) $rule->match_value,
            'between' => $this->amountIsBetween($transaction, $rule),
            default => false,
        };
    }

    private function amountIsBetween(Transaction $transaction, ReconciliationRule $rule): bool
    {
        $lower = $this->compareRuleAmount((string) $transaction->amount, (string) $rule->match_value);
        $upper = $this->compareRuleAmount((string) $transaction->amount, (string) $rule->match_value_secondary);

        return $lower !== null && $upper !== null && $lower >= 0 && $upper <= 0;
    }

    private function compareRuleAmount(string $amount, string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)\z/', $value) !== 1) {
            return null;
        }

        // Keep every rule decimal: truncating to cents could match a sub-cent
        // threshold against a transaction that does not actually satisfy it.
        $scale = max(2, strlen(explode('.', $value, 2)[1] ?? ''));

        return bccomp($amount, $value, $scale);
    }
}
