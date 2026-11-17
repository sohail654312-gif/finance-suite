<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ApprovableApproved;
use App\Models\BankConnection;
use App\Models\OutboundPayment;
use App\Services\RevolutService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes an outbound payment once its approval clears. Fires for BOTH paths:
 * the auto-approve (below-threshold) case synchronously inside submitForApproval,
 * and the deferred case when the final approver approves. Synchronous (not
 * queued) so the controller can read the outcome in the same request.
 */
class SendApprovedOutboundPayment
{
    public function __construct(private readonly RevolutService $revolut) {}

    public function handle(ApprovableApproved $event): void
    {
        $payment = $event->approvable;

        if (! $payment instanceof OutboundPayment
            || $payment->approval_status !== 'approved'
            || $payment->status !== OutboundPayment::STATUS_PENDING_APPROVAL) {
            return;
        }

        $connection = $payment->bankConnection;

        if (! $connection instanceof BankConnection) {
            $payment->update(['status' => OutboundPayment::STATUS_FAILED]);

            return;
        }

        try {
            // idempotency_key -> Revolut request_id: a retry returns the same
            // payment rather than double-spending.
            $result = $this->revolut->sendPayment($connection, [
                'account_id' => $payment->account_id,
                'receiver' => $payment->receiver,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'request_id' => $payment->idempotency_key,
            ]);

            $payment->update([
                'status' => OutboundPayment::STATUS_SENT,
                'result' => $result,
                'request_id' => $payment->idempotency_key,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            $payment->update(['status' => OutboundPayment::STATUS_FAILED]);
            Log::error('Outbound payment send failed', [
                'payment_id' => $payment->id,
                'exception_class' => $e::class,
            ]);
        }
    }
}
