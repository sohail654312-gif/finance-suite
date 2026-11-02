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
        if ($invoice->journal_entry_id === null) {
            throw new RuntimeException('Invoice '.((int) $invoice->getKey()).' is not posted.');
        }
        if ($invoice->payments()->whereNotNull('journal_entry_id')->exists()) {
            throw new RuntimeException('Reverse the invoice\'s posted payments before unposting the invoice.');
        }
        if (RevenueSchedule::where('invoice_id', $invoice->getKey())
            ->whereHas('entries', fn ($q) => $q->where('recognized', true))
            ->exists()) {
            throw new RuntimeException('Cannot unpost: revenue has already been recognized against this invoice.');
        }

        DB::transaction(function () use ($invoice): void {
            $entry = $invoice->journalEntry;
            if (! $entry instanceof JournalEntry) {
                throw new RuntimeException('Linked journal entry is missing.');
            }
            $entry->reverse();
            $invoice->journal_entry_id = null;
            $invoice->save();
        });
    }

    public function reversePayment(Payment $payment): void
    {
        if ($payment->journal_entry_id === null) {
            throw new RuntimeException('Payment '.((int) $payment->getKey()).' is not posted.');
        }

        DB::transaction(function () use ($payment): void {
            $entry = $payment->journalEntry;
            if (! $entry instanceof JournalEntry) {
                throw new RuntimeException('Linked journal entry is missing.');
            }
            $entry->reverse();
            $payment->journal_entry_id = null;
            $payment->save();
            $payment->invoice?->recomputePaymentStatus();
        });
    }
}
