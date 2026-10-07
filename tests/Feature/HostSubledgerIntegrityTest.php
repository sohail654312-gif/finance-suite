<?php

use App\Models\Account;
use App\Models\Bill;
use App\Models\CreditMemo;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesReceipt;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorCredit;
use App\Services\TeamManagementService;

beforeEach(function (): void {
    $this->subledgerUser = User::factory()->create();
    $this->subledgerTeam = app(TeamManagementService::class)->createPersonalTeamForUser($this->subledgerUser);
    $this->actingAs($this->subledgerUser->fresh());
});

it('recalculates bill and customer credit totals after line creation, edits and deletion', function (string $document, string $taxMode): void {
    $rate = new TaxRate(['name' => 'Synthetic VAT', 'rate' => 20, 'is_active' => true]);
    $rate->team_id = $this->subledgerTeam->id;
    $rate->save();
    $header = ['team_id' => $this->subledgerTeam->id, 'total_amount' => 0, 'tax_rate_id' => $taxMode === 'document' ? $rate->tax_rate_id : null];
    $record = $document === 'bill'
        ? Bill::factory()->create($header)
        : CreditMemo::create($header + ['customer_id' => Customer::factory()->create()->id, 'credit_memo_date' => '2026-10-07']);
    $record->load('items');
    $item = $record->items()->create(['description' => 'Synthetic support', 'quantity' => 2, 'unit_price' => 100, 'tax_rate_id' => $taxMode === 'line' ? $rate->tax_rate_id : null]);
    $record->refresh();
    expect($record->subtotal_amount)->toBe('200.00')
        ->and($record->tax_amount)->toBe($taxMode === 'none' ? '0.00' : '40.00')
        ->and($record->total_amount)->toBe($taxMode === 'none' ? '200.00' : '240.00');
    $record->load('items');
    $item->update(['quantity' => 3, 'unit_price' => 50]);
    $record->refresh();
    expect($record->subtotal_amount)->toBe('150.00')
        ->and($record->total_amount)->toBe($taxMode === 'none' ? '150.00' : '180.00');
    $item->delete();
    $record->refresh();
    expect($record->subtotal_amount)->toBe('0.00')
        ->and($record->tax_amount)->toBe('0.00')
        ->and($record->total_amount)->toBe('0.00');
})->with([
    'untaxed bill' => ['bill', 'none'],
    'bill line tax' => ['bill', 'line'],
    'bill document tax' => ['bill', 'document'],
    'untaxed customer credit' => ['credit', 'none'],
    'credit line tax' => ['credit', 'line'],
    'credit document tax' => ['credit', 'document'],
]);

it('tracks supplier payment application and restores open status after a payment is removed', function (): void {
    $bill = Bill::factory()->create(['total_amount' => 100, 'amount_paid' => 0, 'status' => 'open', 'payment_status' => 'unpaid']);
    $partial = $bill->recordPayment(['payment_date' => '2026-10-07', 'amount' => 40, 'payment_method' => 'bank_transfer']);
    expect($bill->fresh()->amount_due)->toEqual(60)
        ->and($bill->fresh()->payment_status)->toBe('partial');
    $remainder = $bill->recordPayment(['payment_date' => '2026-10-07', 'amount' => 60, 'payment_method' => 'bank_transfer']);
    expect($bill->fresh()->payment_status)->toBe('paid')->and($bill->fresh()->status)->toBe('paid');
    $remainder->delete();
    expect($bill->fresh()->amount_paid)->toBe('40.00')
        ->and($bill->fresh()->payment_status)->toBe('partial')
        ->and($bill->fresh()->status)->toBe('open');
    $partial->delete();
    expect($bill->fresh()->amount_paid)->toBe('0.00')
        ->and($bill->fresh()->payment_status)->toBe('unpaid')
        ->and($bill->fresh()->status)->toBe('open');
});

it('applies customer credit partially then fully and restores remaining credit after removal', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->subledgerTeam->id]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'team_id' => $this->subledgerTeam->id, 'total_amount' => 100]);
    $credit = CreditMemo::create(['team_id' => $this->subledgerTeam->id, 'customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'credit_memo_date' => '2026-10-07', 'total_amount' => 100, 'status' => 'open']);
    $first = $credit->applyToInvoice($invoice->id, 40, '2026-10-07');
    expect($first->invoice->id)->toBe($invoice->id)
        ->and($first->creditMemo->credit_memo_id)->toBe($credit->credit_memo_id)
        ->and($credit->amount_remaining)->toEqual(60)
        ->and($credit->is_fully_applied)->toBeFalse();
    $second = $credit->applyToInvoice($invoice->id, 60);
    expect($credit->fresh()->status)->toBe('applied')->and($credit->fresh()->is_fully_applied)->toBeTrue();
    $second->delete();
    expect($credit->fresh()->status)->toBe('open')->and($credit->fresh()->amount_remaining)->toEqual(60);
    $first->delete();
    expect($credit->fresh()->amount_applied)->toBe('0.00');
});

it('rejects missing invoices, wrong customers and credit exceeding the remaining amount', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->subledgerTeam->id]);
    $credit = CreditMemo::create(['team_id' => $this->subledgerTeam->id, 'customer_id' => $customer->id, 'credit_memo_date' => '2026-10-07', 'total_amount' => 100, 'status' => 'open']);
    $otherInvoice = Invoice::factory()->create();
    expect(fn () => $credit->applyToInvoice(999999, 10))->toThrow(Exception::class, 'Invoice not found')
        ->and(fn () => $credit->applyToInvoice($otherInvoice->id, 10))->toThrow(Exception::class, 'same customer')
        ->and(fn () => $credit->applyToInvoice($otherInvoice->id, 101))->toThrow(Exception::class, 'remaining credit');
    expect($credit->applications()->count())->toBe(0);
    $credit->markAsVoid();
    expect($credit->fresh()->status)->toBe('void');
});

it('preserves an explicit supplier credit void decision during balance recalculation', function (): void {
    $vendor = Vendor::factory()->create(['team_id' => $this->subledgerTeam->id]);
    $credit = VendorCredit::create(['vendor_id' => $vendor->vendor_id, 'credit_date' => '2026-10-07', 'total_amount' => 100]);
    expect($credit->amount_remaining)->toBe('100.00')->and($credit->isFullyApplied())->toBeFalse();
    $credit->void();
    expect($credit->fresh()->status)->toBe('void')->and($credit->fresh()->amount_remaining)->toBe('100.00');
});

it('reconciles a synthetic sales receipt and its partial refund using the original tax ratio', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->subledgerTeam->id]);
    $account = Account::factory()->create(['team_id' => $this->subledgerTeam->id]);
    $rate = TaxRate::create(['name' => 'Synthetic VAT', 'rate' => 20, 'is_active' => true]);
    $receipt = SalesReceipt::create(['customer_id' => $customer->id, 'sales_receipt_date' => '2026-10-07', 'payment_method' => 'cash', 'tax_rate_id' => $rate->tax_rate_id, 'deposit_to_account_id' => $account->id]);
    $item = $receipt->items()->create(['description' => 'Synthetic support', 'quantity' => 2, 'unit_price' => 100, 'account_id' => $account->id]);
    $receipt->calculateTotals();
    expect($receipt->total_amount)->toBe('240.00')
        ->and($item->salesReceipt->sales_receipt_id)->toBe($receipt->sales_receipt_id)
        ->and($item->account->id)->toBe($account->id)
        ->and($receipt->customer->id)->toBe($customer->id)
        ->and($receipt->depositAccount->id)->toBe($account->id);
    $refund = $receipt->createRefund([['description' => 'One returned support unit', 'quantity' => 1, 'unit_price' => 100, 'account_id' => $account->id]], 'other');
    expect($refund->subtotal_amount)->toBe('100.00')->and($refund->tax_amount)->toBe('20.00')->and($refund->total_amount)->toBe('120.00')
        ->and($refund->isCompleted())->toBeFalse();
    $refund->process();
    expect($refund->fresh()->isCompleted())->toBeTrue();
    $refund->void();
    expect($refund->fresh()->status)->toBe('void');
    $receipt->void();
    expect($receipt->fresh()->isVoid())->toBeTrue();
});
