<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\RevenueSchedule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a customer invoice to the general ledger as a balanced journal entry:
 * Dr Accounts Receivable / Cr Deferred Revenue (when the invoice has a revenue
 * schedule) or Cr Sales Revenue (otherwise), for the pre-tax total.
 *
 * ponytail: per-line tax_amount -> Sales Tax Payable (2200) is deferred to a
 * later slice; this posts total_amount only (balanced pre-tax).
 */
class InvoicePostingService
{
    public function post(Invoice $invoice): JournalEntry
    {
        $entry = DB::transaction(function () use ($invoice): JournalEntry {
            $source = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            $teamId = (int) $source->team_id;
            if ($teamId < 1) {
                throw new RuntimeException('Invoice must belong to a team.');
            }
            if ($source->journal_entry_id !== null) {
                $existing = $source->journalEntry;
                if (! $existing instanceof JournalEntry || (int) $existing->team_id !== $teamId || ! $existing->is_posted) {
                    throw new RuntimeException('Invoice linked to an invalid journal entry.');
                }

                return $existing;
            }
            $amount = (string) $source->total_amount;
            if (bccomp($amount, '0', 2) <= 0) {
                throw new RuntimeException('Invoice amount must be positive.');
            }
            $receivable = $this->resolveByNumber($teamId, 1100);
            $schedule = RevenueSchedule::query()->where('invoice_id', $source->getKey())->lockForUpdate()->first();
            if ($schedule instanceof RevenueSchedule
                && ((int) $schedule->team_id !== $teamId || bccomp((string) $schedule->total_amount, $amount, 2) !== 0)) {
                throw new RuntimeException('Revenue schedule must match the invoice team and total.');
            }
            $credit = $schedule instanceof RevenueSchedule
                ? $this->resolveById($teamId, (int) $schedule->deferred_account_id)
                : $this->resolveByNumber($teamId, 4000);

            $entry = new JournalEntry();
            // team_id + user_id are NOT fillable and there is no auth() here; set
            // them explicitly so the entry is team-scoped (never default team 1)
            // and satisfies the non-nullable user_id FK (team owner).
            $entry->forceFill([
                'entry_date' => $source->invoice_date,
                'entry_type' => 'general',
                'reference_number' => (string) $source->getKey(),
                'memo' => 'Invoice '.$source->invoice_number,
                'team_id' => $teamId,
                'user_id' => $source->team?->user_id,
            ])->save();

            $entry->lines()->create([
                'account_id' => $receivable->getKey(),
                'debit_amount' => $amount,
                'credit_amount' => 0,
                'description' => 'Accounts Receivable',
            ]);
            $entry->lines()->create([
                'account_id' => $credit->getKey(),
                'debit_amount' => 0,
                'credit_amount' => $amount,
                'description' => $schedule instanceof RevenueSchedule ? 'Deferred revenue' : 'Sales revenue',
            ]);

            $entry->post();

            $source->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            return $entry;
        });
        $invoice->refresh();

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

    private function resolveById(int $teamId, int $id): Account
    {
        $account = Account::where('team_id', $teamId)->where('id', $id)->first();
        if (! $account instanceof Account) {
            throw new RuntimeException("Revenue-schedule account {$id} not found for team {$teamId}.");
        }

        return $account;
    }
}
