<?php

use App\Models\Account;
use App\Models\Bill;
use App\Models\Category;
use App\Models\CreditMemo;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DelayedCharge;
use App\Models\Estimate;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\PaymentTerm;
use App\Models\PurchaseOrder;
use App\Models\SalesReceipt;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Transaction;
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
    $record = match ($document) {
        'bill' => Bill::factory()->create($header),
        'credit' => CreditMemo::create($header + ['customer_id' => Customer::factory()->create()->id, 'credit_memo_date' => '2026-10-07']),
        'estimate' => Estimate::create($header + ['customer_id' => Customer::factory()->create()->id, 'estimate_date' => '2026-10-07']),
    };
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
    'untaxed estimate' => ['estimate', 'none'],
    'estimate line tax' => ['estimate', 'line'],
    'estimate document tax' => ['estimate', 'document'],
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
    expect($customer->creditMemos()->sole()->getKey())->toBe($credit->getKey());
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
    expect($customer->salesReceipts()->sole()->getKey())->toBe($receipt->getKey())
        ->and($customer->refundReceipts()->sole()->getKey())->toBe($refund->getKey());
    expect($refund->subtotal_amount)->toBe('100.00')->and($refund->tax_amount)->toBe('20.00')->and($refund->total_amount)->toBe('120.00')
        ->and($refund->isCompleted())->toBeFalse();
    $refund->process();
    expect($refund->fresh()->isCompleted())->toBeTrue();
    $refund->void();
    expect($refund->fresh()->status)->toBe('void');
    $receipt->void();
    expect($receipt->fresh()->isVoid())->toBeTrue();
});

it('converts one accepted estimate to an owned invoice with matching lines and tax', function (string $taxMode): void {
    $customer = Customer::factory()->create(['team_id' => $this->subledgerTeam->id]);
    $rate = new TaxRate(['name' => 'Synthetic VAT', 'rate' => 20, 'is_active' => true]);
    $rate->team_id = $this->subledgerTeam->id;
    $rate->save();
    $estimate = Estimate::create(['total_amount' => 0, 'team_id' => $this->subledgerTeam->id, 'customer_id' => $customer->id, 'estimate_date' => '2026-10-07', 'status' => 'accepted', 'tax_rate_id' => $taxMode === 'document' ? $rate->getKey() : null]);
    $estimate->items()->create(['description' => 'Synthetic support', 'quantity' => 2, 'unit_price' => 100, 'tax_rate_id' => $taxMode === 'line' ? $rate->getKey() : null]);
    $estimate->refresh();
    $staleCopy = Estimate::findOrFail($estimate->getKey());
    $invoice = $estimate->convertToInvoice();
    expect($invoice->team_id)->toBe($this->subledgerTeam->id)
        ->and($invoice->customer_id)->toBe($customer->id)
        ->and($invoice->total_amount)->toBe('200.00')
        ->and($invoice->getTotalWithTax())->toEqual($taxMode === 'none' ? 200 : 240)
        ->and($invoice->items()->sole()->description)->toBe('Synthetic support')
        ->and($estimate->fresh()->invoice_id)->toBe($invoice->id)
        ->and($estimate->invoice->id)->toBe($invoice->id)
        ->and($customer->estimates()->sole()->getKey())->toBe($estimate->getKey());
    expect(fn () => $staleCopy->convertToInvoice())->toThrow(DomainException::class, 'already been converted');
    expect(Invoice::count())->toBe(1);
})->with(['none', 'line', 'document']);

it('rejects estimate conversion before acceptance and without invoiceable line items', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->subledgerTeam->id]);
    $estimate = Estimate::create(['total_amount' => 0, 'team_id' => $this->subledgerTeam->id, 'customer_id' => $customer->id, 'estimate_date' => '2026-10-07']);
    expect(fn () => $estimate->convertToInvoice())->toThrow(DomainException::class, 'Only accepted estimates');
    $estimate->accept();
    expect(fn () => $estimate->convertToInvoice())->toThrow(DomainException::class, 'requires line items');
    expect(Invoice::count())->toBe(0)->and($estimate->fresh()->invoice_id)->toBeNull();
});

it('rolls estimate conversion back when the accepted totals do not match the copied invoice', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->subledgerTeam->id]);
    $estimate = Estimate::create(['total_amount' => 0, 'team_id' => $this->subledgerTeam->id, 'customer_id' => $customer->id, 'estimate_date' => '2026-10-07', 'status' => 'accepted']);
    $estimate->items()->create(['description' => 'Synthetic support', 'quantity' => 1, 'unit_price' => 100]);
    $estimate->update(['total_amount' => 999]);
    expect(fn () => $estimate->convertToInvoice())->toThrow(DomainException::class, 'totals must match');
    expect(Invoice::count())->toBe(0)->and($estimate->fresh()->invoice_id)->toBeNull();
});

it('rejects an estimate linked to a customer owned by another company', function (): void {
    $other = User::factory()->create();
    $otherTeam = app(TeamManagementService::class)->createPersonalTeamForUser($other);
    $customer = Customer::factory()->create(['team_id' => $otherTeam->id]);
    $estimate = Estimate::create(['total_amount' => 0, 'team_id' => $this->subledgerTeam->id, 'customer_id' => $customer->id, 'estimate_date' => '2026-10-07', 'status' => 'accepted']);
    $estimate->items()->create(['description' => 'Synthetic support', 'quantity' => 1, 'unit_price' => 100]);
    expect(fn () => $estimate->convertToInvoice())->toThrow(DomainException::class, 'same company');
    expect(Invoice::count())->toBe(0);
});

it('keeps estimate response timestamps and expiration behavior consistent', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 7)->startOfDay());
    $estimate = Estimate::create(['total_amount' => 0, 'team_id' => $this->subledgerTeam->id, 'customer_id' => Customer::factory()->create()->id, 'estimate_date' => '2026-10-07', 'expiration_date' => '2026-10-10']);
    expect($estimate->is_expired)->toBeFalse()->and($estimate->days_until_expiration)->toEqual(3);
    $estimate->markAsSent();
    expect($estimate->sent_at)->not->toBeNull()->and($estimate->status)->toBe('sent');
    $estimate->markAsViewed();
    $viewedAt = $estimate->viewed_at->toIso8601String();
    $this->travel(1)->days();
    $estimate->markAsViewed();
    expect($estimate->viewed_at->toIso8601String())->toBe($viewedAt);
    $estimate->decline('Synthetic proposal declined');
    expect($estimate->fresh()->decline_reason)->toBe('Synthetic proposal declined');
    $estimate->update(['expiration_date' => '2026-10-01', 'status' => 'sent']);
    expect($estimate->is_expired)->toBeTrue()->and($estimate->days_until_expiration)->toBeLessThan(0);
    $estimate->accept();
    expect($estimate->is_expired)->toBeFalse()->and($estimate->accepted_at)->not->toBeNull();
    $estimate->update(['expiration_date' => null]);
    expect($estimate->days_until_expiration)->toBeNull();
});

it('keeps a delayed customer charge attached to the actual customer primary key', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->subledgerTeam->id]);
    $other = Customer::factory()->create(['team_id' => $this->subledgerTeam->id]);
    $charge = DelayedCharge::create(['customer_id' => $customer->id, 'charge_date' => '2026-10-07', 'description' => 'Synthetic future work', 'quantity' => 2, 'unit_price' => 50]);
    expect($charge->amount)->toBe('100.00')
        ->and($customer->delayedCharges()->sole()->getKey())->toBe($charge->getKey())
        ->and($other->delayedCharges()->count())->toBe(0);
});

it('resolves account and transaction currencies through the stored currency keys', function (): void {
    $currency = Currency::create(['code' => 'GBP', 'name' => 'Pound sterling', 'symbol' => '£']);
    $other = Currency::create(['code' => 'USD', 'name' => 'US dollar', 'symbol' => '$']);
    $account = Account::factory()->create(['currency_id' => $currency->getKey()]);
    $transaction = Transaction::factory()->create(['currency_id' => $currency->getKey()]);
    expect($account->currency->getKey())->toBe($currency->getKey())
        ->and($transaction->currency->getKey())->toBe($currency->getKey())
        ->and($currency->accounts()->sole()->getKey())->toBe($account->getKey())
        ->and($currency->transactions()->sole()->getKey())->toBe($transaction->getKey())
        ->and($other->accounts()->count())->toBe(0)
        ->and($other->transactions()->count())->toBe(0);
});

it('persists account and transaction categories in both directions without duplicate associations', function (): void {
    $category = Category::create(['name' => 'Synthetic operating activity']);
    $account = Account::factory()->create();
    $transaction = Transaction::factory()->create();
    $account->categories()->syncWithoutDetaching([$category->id]);
    $transaction->categories()->syncWithoutDetaching([$category->id]);
    $transaction->categories()->syncWithoutDetaching([$category->id]);
    expect($category->accounts()->sole()->getKey())->toBe($account->id)
        ->and($category->transactions()->sole()->getKey())->toBe($transaction->getKey())
        ->and($account->categories()->sole()->getKey())->toBe($category->id)
        ->and($transaction->categories()->sole()->getKey())->toBe($category->id);
    $category->delete();
    $this->assertDatabaseCount('account_category', 0);
    $this->assertDatabaseCount('category_transaction', 0);
    expect($account->fresh())->not->toBeNull()->and($transaction->fresh())->not->toBeNull();
});

it('keeps inventory movement linked to the actual transaction primary key', function (): void {
    $account = Account::factory()->create();
    $item = InventoryItem::create(['name' => 'Synthetic stock', 'sku' => 'SYNTHETIC-1', 'unit_price' => 25, 'account_id' => $account->id]);
    $transaction = Transaction::factory()->create(['amount' => 50]);
    $movement = $transaction->inventoryTransactions()->create(['inventory_item_id' => $item->id, 'quantity' => 2, 'unit_price' => 25, 'transaction_type' => 'purchase']);
    expect($movement->transaction->getKey())->toBe($transaction->getKey())
        ->and($movement->inventoryItem->getKey())->toBe($item->id)
        ->and($item->transactions()->sole()->getKey())->toBe($movement->getKey())
        ->and($transaction->inventoryTransactions()->sole()->getKey())->toBe($movement->getKey());
});

it('retrieves tax-bearing invoices from their lines and returns each invoice once', function (): void {
    $rate = TaxRate::create(['name' => 'Synthetic VAT', 'rate' => 20, 'is_active' => true]);
    $otherRate = TaxRate::create(['name' => 'Synthetic reduced rate', 'rate' => 5, 'is_active' => true]);
    $vendor = Vendor::factory()->create();
    $invoice = Invoice::factory()->create(['vendor_id' => $vendor->getKey()]);
    foreach (['First service', 'Second service'] as $description) {
        $invoice->items()->create(['description' => $description, 'quantity' => 1, 'unit_price' => 100, 'tax_rate_id' => $rate->getKey()]);
    }
    $otherInvoice = Invoice::factory()->create();
    $otherInvoice->items()->create(['description' => 'Reduced service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate_id' => $otherRate->getKey()]);
    expect($rate->invoices()->sole()->getKey())->toBe($invoice->id)
        ->and($otherRate->invoices()->sole()->getKey())->toBe($otherInvoice->id)
        ->and(TaxRate::with('invoices')->findOrFail($rate->getKey())->invoices->pluck('id')->all())->toBe([$invoice->id])
        ->and($vendor->invoices()->sole()->getKey())->toBe($invoice->id)
        ->and($invoice->fresh()->getTotalWithTax())->toEqual(240);
});

it('persists customer and supplier tax-rate assignments against their real primary keys', function (): void {
    $customer = Customer::factory()->create();
    $term = PaymentTerm::create(['payment_term_name' => 'Synthetic thirty days', 'payment_term_description' => 'Synthetic terms', 'payment_term_number_of_days' => 30]);
    $supplier = Supplier::create(['payment_term_id' => $term->getKey(), 'supplier_first_name' => 'Synthetic', 'supplier_last_name' => 'Supplier', 'supplier_email' => 'supplier@example.test', 'supplier_address' => 'Synthetic address', 'supplier_phone_number' => '0000000000', 'supplier_limit_credit' => 1000, 'supplier_tin' => 12345]);
    $rate = TaxRate::create(['name' => 'Synthetic VAT', 'rate' => 20, 'is_active' => true]);
    $rate->customers()->attach($customer->id);
    $rate->suppliers()->attach($supplier->getKey());
    expect($rate->customers()->sole()->getKey())->toBe($customer->id)
        ->and($rate->suppliers()->sole()->getKey())->toBe($supplier->getKey());
    $rate->delete();
    $this->assertDatabaseCount('customer_tax_rate', 0);
    $this->assertDatabaseCount('supplier_tax_rate', 0);
    expect($customer->fresh())->not->toBeNull()->and($supplier->fresh())->not->toBeNull();
});

it('keeps optional purchase-order transactions and unlinks them safely when the order is deleted', function (): void {
    $term = PaymentTerm::create(['payment_term_name' => 'Synthetic immediate', 'payment_term_description' => 'Synthetic terms', 'payment_term_number_of_days' => 0]);
    $supplier = Supplier::create(['payment_term_id' => $term->getKey(), 'supplier_first_name' => 'Synthetic', 'supplier_last_name' => 'Supplier', 'supplier_email' => 'supplier@example.test', 'supplier_address' => 'Synthetic address', 'supplier_phone_number' => '0000000000', 'supplier_limit_credit' => 1000, 'supplier_tin' => 12345]);
    $order = PurchaseOrder::create(['supplier_id' => $supplier->getKey(), 'po_number' => 'SYNTHETIC-PO-1', 'order_date' => '2026-10-07', 'total_amount' => 50]);
    $unrelated = Transaction::factory()->create();
    $transaction = $order->transactions()->create(['transaction_date' => '2026-10-07', 'amount' => 50, 'type' => 'debit']);
    expect($order->transactions()->sole()->getKey())->toBe($transaction->getKey())
        ->and($unrelated->fresh()->purchase_order_id)->toBeNull();
    $order->delete();
    expect($transaction->fresh()->purchase_order_id)->toBeNull()
        ->and($transaction->fresh()->amount)->toBe('50.00');
});
