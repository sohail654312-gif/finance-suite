<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a customer payment to the general ledger as a balanced journal entry:
 * Dr Cash (1000) / Cr Accounts Receivable (1100) for the payment amount, then
 * recomputes the invoice's payment_status from the sum of its payments.
 *
 * ponytail: overpayment posts the full amount (AR can go negative — a customer
 * credit); allocation across multiple invoices + reversal are later slices.
 */
class PaymentPostingService
{
    public function post(Payment $payment): JournalEntry
    {
        $entry = DB::transaction(function () use ($payment): JournalEntry {
            $source = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $teamId = (int) $source->team_id;
            if ($teamId < 1) {
                throw new RuntimeException('Payment must belong to a team.');
            }
            $invoice = $source->invoice()->lockForUpdate()->first();
            if (! $invoice instanceof \App\Models\Invoice || (int) $invoice->team_id !== $teamId) {
                throw new RuntimeException('Payment invoice must belong to the same team.');
            }
            if ($source->journal_entry_id !== null) {
                $existing = $source->journalEntry;
                if (! $existing instanceof JournalEntry || (int) $existing->team_id !== $teamId || ! $existing->is_posted) {
                    throw new RuntimeException('Payment linked to an invalid journal entry.');
                }

                return $existing;
            }
            $amount = (string) $source->payment_amount;
            if (bccomp($amount, '0', 2) <= 0) {
                throw new RuntimeException('Payment amount must be positive.');
            }
            $cash = $this->resolveByNumber($teamId, 1000);
            $receivable = $this->resolveByNumber($teamId, 1100);

            $entry = new JournalEntry();
            $entry->forceFill([
                'entry_date' => $source->payment_date,
                'entry_type' => 'general',
                'reference_number' => (string) $source->getKey(),
                'memo' => 'Payment '.$source->getKey(),
                'team_id' => $teamId,
                'user_id' => $source->team?->user_id,
            ])->save();

            $entry->lines()->create([
                'account_id' => $cash->getKey(),
                'debit_amount' => $amount,
                'credit_amount' => 0,
                'description' => 'Cash',
            ]);
            $entry->lines()->create([
                'account_id' => $receivable->getKey(),
                'debit_amount' => 0,
                'credit_amount' => $amount,
                'description' => 'Accounts Receivable',
            ]);

            $entry->post();

            $source->forceFill(['journal_entry_id' => $entry->getKey()])->save();
            $invoice->recomputePaymentStatus();

            return $entry;
        });
        $payment->refresh();

        return $entry;
    }

    private function resolveByNumber(int $teamId, int $number): Account
    {
        $account = Account::where('team_id', $teamId)->where('account_number', $number)->first();
        if (! $account instanceof Account) {
            throw new RuntimeException("Account {$number} not found for team {$teamId}. Provision the chart of accounts first (tenants:provision-chart).");
        }

        return $account;
    }
}
