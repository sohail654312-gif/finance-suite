<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\RevenueSchedule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Corrects a posted invoice or payment with a balanced compensating journal.
 * The original posting and its timestamp remain immutable; the reversal links
 * back to that journal. The source link is cleared to permit a later posting,
 * and derived payment status is recomputed. Recognized revenue remains guarded.
 */
class PostingReversalService
{
    public function reverseInvoice(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $source = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            if ($source->journal_entry_id === null) {
                throw new RuntimeException('Invoice '.((int) $source->getKey()).' is not posted.');
            }
            if ($source->payments()->whereNotNull('journal_entry_id')->exists()) {
                throw new RuntimeException('Reverse the invoice\'s posted payments before unposting the invoice.');
            }
            if (RevenueSchedule::where('invoice_id', $source->getKey())
                ->whereHas('entries', fn ($q) => $q->where('recognized', true))
                ->exists()) {
                throw new RuntimeException('Cannot unpost: revenue has already been recognized against this invoice.');
            }

            $entry = $this->ownedJournal($source->journal_entry_id, $source->team_id);
            $entry->reverse();
            $source->journal_entry_id = null;
            $source->save();
        });

        $invoice->refresh();
    }

    public function reversePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $source = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($source->journal_entry_id === null) {
                throw new RuntimeException('Payment '.((int) $source->getKey()).' is not posted.');
            }

            $linkedInvoice = $source->invoice()->lockForUpdate()->first();
            if ($linkedInvoice instanceof Invoice && (int) $linkedInvoice->team_id !== (int) $source->team_id) {
                throw new RuntimeException('Linked invoice belongs to another team.');
            }

            $entry = $this->ownedJournal($source->journal_entry_id, $source->team_id);
            $entry->reverse();
            $source->journal_entry_id = null;
            $source->save();
            $linkedInvoice?->recomputePaymentStatus();
        });

        $payment->refresh();
    }

    private function ownedJournal(int $journalId, ?int $teamId): JournalEntry
    {
        $entry = JournalEntry::query()->whereKey($journalId)->lockForUpdate()->first();
        if (! $entry instanceof JournalEntry) {
            throw new RuntimeException('Linked journal entry is missing.');
        }
        if ($teamId === null || $teamId < 1 || (int) $entry->team_id !== $teamId) {
            throw new RuntimeException('Linked journal entry belongs to another team.');
        }

        return $entry;
    }
}
