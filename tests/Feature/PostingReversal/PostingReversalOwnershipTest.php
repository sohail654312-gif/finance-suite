<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\InvoicePostingService;
use App\Services\PaymentPostingService;
use App\Services\PostingReversalService;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{Team, Invoice, JournalEntry} */
function reversalOwnershipInvoice(): array
{
    $user = User::factory()->create();
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Reversal ownership', 'personal_team' => false]);
    app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $invoice = Invoice::factory()->create([
        'team_id' => $team->id, 'customer_id' => $customer->id,
        'invoice_date' => '2026-06-01', 'total_amount' => '300.00',
    ]);
    $journal = app(InvoicePostingService::class)->post($invoice);

    return [$team, $invoice->refresh(), $journal];
}

/** @return array{Payment, JournalEntry} */
function reversalOwnershipPayment(Team $team, Invoice $invoice): array
{
    $payment = Payment::query()->create([
        'team_id' => $team->id, 'invoice_id' => $invoice->id,
        'payment_date' => '2026-06-05', 'payment_amount' => '120.00',
    ]);
    $journal = app(PaymentPostingService::class)->post($payment);

    return [$payment->refresh(), $journal];
}

it('denies a forged invoice link to another tenant journal without financial effects', function (): void {
    [, $invoice, $ownJournal] = reversalOwnershipInvoice();
    [$foreignTeam, , $foreignJournal] = reversalOwnershipInvoice();
    $invoice->forceFill(['journal_entry_id' => $foreignJournal->id])->save();
    $before = Account::query()->where('team_id', $foreignTeam->id)->pluck('balance', 'id')->all();

    expect(fn () => app(PostingReversalService::class)->reverseInvoice($invoice))
        ->toThrow(RuntimeException::class, 'another team');

    expect($invoice->fresh()->journal_entry_id)->toBe($foreignJournal->id)
        ->and($foreignJournal->reversal()->exists())->toBeFalse()
        ->and($ownJournal->reversal()->exists())->toBeFalse()
        ->and(Account::query()->where('team_id', $foreignTeam->id)->pluck('balance', 'id')->all())->toBe($before);
});

it('denies a forged payment link to another tenant journal without financial effects', function (): void {
    [$team, $invoice] = reversalOwnershipInvoice();
    [$payment, $ownJournal] = reversalOwnershipPayment($team, $invoice);
    [$foreignTeam, $foreignInvoice] = reversalOwnershipInvoice();
    [, $foreignJournal] = reversalOwnershipPayment($foreignTeam, $foreignInvoice);
    $payment->forceFill(['journal_entry_id' => $foreignJournal->id])->save();
    $before = Account::query()->where('team_id', $foreignTeam->id)->pluck('balance', 'id')->all();

    expect(fn () => app(PostingReversalService::class)->reversePayment($payment))
        ->toThrow(RuntimeException::class, 'another team');

    expect($payment->fresh()->journal_entry_id)->toBe($foreignJournal->id)
        ->and($foreignJournal->reversal()->exists())->toBeFalse()
        ->and($ownJournal->reversal()->exists())->toBeFalse()
        ->and(Account::query()->where('team_id', $foreignTeam->id)->pluck('balance', 'id')->all())->toBe($before);
});

it('uses the persisted invoice link instead of cached caller attributes and relations', function (): void {
    [, $invoice, $ownJournal] = reversalOwnershipInvoice();
    [, , $foreignJournal] = reversalOwnershipInvoice();
    $invoice->journal_entry_id = $foreignJournal->id;
    $invoice->setRelation('journalEntry', $foreignJournal);

    app(PostingReversalService::class)->reverseInvoice($invoice);

    expect($invoice->journal_entry_id)->toBeNull()
        ->and($ownJournal->reversal()->firstOrFail()->is_posted)->toBeTrue()
        ->and($foreignJournal->reversal()->exists())->toBeFalse();
});

it('uses the persisted payment link instead of cached caller attributes and relations', function (): void {
    [$team, $invoice] = reversalOwnershipInvoice();
    [$payment, $ownJournal] = reversalOwnershipPayment($team, $invoice);
    [$foreignTeam, $foreignInvoice] = reversalOwnershipInvoice();
    [, $foreignJournal] = reversalOwnershipPayment($foreignTeam, $foreignInvoice);
    $payment->journal_entry_id = $foreignJournal->id;
    $payment->setRelation('journalEntry', $foreignJournal);

    app(PostingReversalService::class)->reversePayment($payment);

    expect($payment->journal_entry_id)->toBeNull()
        ->and($ownJournal->reversal()->firstOrFail()->is_posted)->toBeTrue()
        ->and($foreignJournal->reversal()->exists())->toBeFalse()
        ->and($invoice->fresh()->payment_status)->toBe('pending');
});

it('denies a payment reversal whose linked invoice belongs to another tenant', function (): void {
    [$team, $invoice] = reversalOwnershipInvoice();
    [$payment, $ownJournal] = reversalOwnershipPayment($team, $invoice);
    [, $foreignInvoice] = reversalOwnershipInvoice();
    $payment->forceFill(['invoice_id' => $foreignInvoice->id])->save();

    expect(fn () => app(PostingReversalService::class)->reversePayment($payment))
        ->toThrow(RuntimeException::class, 'Linked invoice belongs to another team');
    expect($ownJournal->reversal()->exists())->toBeFalse()
        ->and($payment->fresh()->journal_entry_id)->toBe($ownJournal->id)
        ->and($foreignInvoice->fresh()->payment_status)->toBe('pending');
});
