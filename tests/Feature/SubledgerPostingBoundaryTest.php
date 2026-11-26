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
use App\Services\RevenueRecognitionService;
use App\Services\TenantProvisioningService;

beforeEach(function (): void {
    $user = User::factory()->create();
    $this->postingTeam = Team::forceCreate(['user_id' => $user->id, 'name' => 'Synthetic posting', 'personal_team' => false]);
    app(TenantProvisioningService::class)->provisionChartOfAccounts($this->postingTeam);
    $customer = Customer::factory()->create(['team_id' => $this->postingTeam->id]);
    $this->postingInvoice = Invoice::factory()->create([
        'team_id' => $this->postingTeam->id, 'customer_id' => $customer->id,
        'invoice_date' => '2026-06-01', 'total_amount' => '100.01', 'payment_status' => 'pending',
    ]);
    $this->postingPayment = Payment::create([
        'team_id' => $this->postingTeam->id, 'invoice_id' => $this->postingInvoice->id,
        'payment_date' => '2026-06-02', 'payment_amount' => '100.01',
    ]);
});

it('posts persisted amounts and dates instead of stale or unsaved source attributes', function (string $kind): void {
    $source = $kind === 'invoice' ? $this->postingInvoice : $this->postingPayment;
    $amountColumn = $kind === 'invoice' ? 'total_amount' : 'payment_amount';
    $dateColumn = $kind === 'invoice' ? 'invoice_date' : 'payment_date';
    $source->newQuery()->whereKey($source->getKey())->update([$amountColumn => '75.03', $dateColumn => '2026-06-03']);
    $source->{$amountColumn} = '999.99';
    $source->{$dateColumn} = '2026-01-01';
    $source->team_id = 999999;
    $service = $kind === 'invoice' ? app(InvoicePostingService::class) : app(PaymentPostingService::class);
    $entry = $service->post($source);

    expect($entry->is_posted)->toBeTrue()
        ->and($entry->team_id)->toBe($this->postingTeam->id)
        ->and($entry->entry_date->toDateString())->toBe('2026-06-03')
        ->and($entry->lines()->where('debit_amount', '>', 0)->sole()->debit_amount)->toBe('75.03')
        ->and($source->fresh()->{$amountColumn})->toBe('75.03')
        ->and($source->journal_entry_id)->toBe($entry->id);
})->with(['invoice', 'payment']);

it('uses persisted journal links for stale retries and ignores fabricated in-memory links', function (string $kind): void {
    $source = $kind === 'invoice' ? $this->postingInvoice : $this->postingPayment;
    $stale = $source->fresh();
    $source->journal_entry_id = 999999;
    $service = $kind === 'invoice' ? app(InvoicePostingService::class) : app(PaymentPostingService::class);
    $first = $service->post($source);
    $second = $service->post($stale);
    expect($first->id)->toBe($second->id)
        ->and($stale->journal_entry_id)->toBe($first->id)
        ->and(JournalEntry::count())->toBe(1);
})->with(['invoice', 'payment']);

it('rejects deleted sources without leaving journals or account effects', function (string $kind): void {
    $source = $kind === 'invoice' ? $this->postingInvoice : $this->postingPayment;
    $source->delete();
    $service = $kind === 'invoice' ? app(InvoicePostingService::class) : app(PaymentPostingService::class);
    expect(fn () => $service->post($source))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(JournalEntry::count())->toBe(0)
        ->and(Account::where('team_id', $this->postingTeam->id)->where('balance', '!=', 0)->count())->toBe(0);
})->with(['invoice', 'payment']);

it('rejects nonpositive persisted source amounts atomically', function (string $kind, string $amount): void {
    $source = $kind === 'invoice' ? $this->postingInvoice : $this->postingPayment;
    $source->update([$kind === 'invoice' ? 'total_amount' : 'payment_amount' => $amount]);
    $service = $kind === 'invoice' ? app(InvoicePostingService::class) : app(PaymentPostingService::class);
    expect(fn () => $service->post($source))->toThrow(RuntimeException::class, 'positive');
    expect($source->fresh()->journal_entry_id)->toBeNull()->and(JournalEntry::count())->toBe(0);
})->with([['invoice', '0.00'], ['invoice', '-0.01'], ['payment', '0.00'], ['payment', '-0.01']]);

it('denies payment allocation to a foreign invoice and leaves both tenants unchanged', function (): void {
    $otherUser = User::factory()->create();
    $otherTeam = Team::forceCreate(['user_id' => $otherUser->id, 'name' => 'Foreign', 'personal_team' => false]);
    $foreignInvoice = Invoice::factory()->create(['team_id' => $otherTeam->id, 'payment_status' => 'pending']);
    $this->postingPayment->update(['invoice_id' => $foreignInvoice->id]);
    expect(fn () => app(PaymentPostingService::class)->post($this->postingPayment))->toThrow(RuntimeException::class, 'same team');
    expect($foreignInvoice->fresh()->payment_status)->toBe('pending')
        ->and($this->postingInvoice->fresh()->payment_status)->toBe('pending')
        ->and($this->postingPayment->fresh()->journal_entry_id)->toBeNull()
        ->and(JournalEntry::count())->toBe(0);
});

it('rejects draft and foreign persisted journal links on retry', function (string $kind, bool $foreign): void {
    $source = $kind === 'invoice' ? $this->postingInvoice : $this->postingPayment;
    $otherUser = User::factory()->create();
    $otherTeam = Team::forceCreate(['user_id' => $otherUser->id, 'name' => 'Foreign', 'personal_team' => false]);
    $journal = JournalEntry::factory()->create([
        'team_id' => $foreign ? $otherTeam->id : $this->postingTeam->id,
        'user_id' => $foreign ? $otherUser->id : $this->postingTeam->user_id,
    ]);
    $source->update(['journal_entry_id' => $journal->id]);
    $service = $kind === 'invoice' ? app(InvoicePostingService::class) : app(PaymentPostingService::class);
    expect(fn () => $service->post($source))->toThrow(RuntimeException::class, 'invalid journal');
    expect(JournalEntry::count())->toBe(1)->and($journal->fresh()->is_posted)->toBeFalse();
})->with([['invoice', false], ['invoice', true], ['payment', false], ['payment', true]]);

it('rejects revenue schedules with a changed amount or foreign team before posting', function (bool $foreign): void {
    $accounts = Account::where('team_id', $this->postingTeam->id)->get()->keyBy('account_number');
    $schedule = app(RevenueRecognitionService::class)->createFromInvoice($this->postingInvoice, 3, $accounts[2400], $accounts[4000]);
    $schedule->update($foreign ? ['team_id' => null] : ['total_amount' => '100.00']);
    expect(fn () => app(InvoicePostingService::class)->post($this->postingInvoice))->toThrow(RuntimeException::class, 'schedule must match');
    expect(JournalEntry::count())->toBe(0)->and($this->postingInvoice->fresh()->journal_entry_id)->toBeNull();
})->with([false, true]);

it('computes the last cent from tenant-owned posted payments only', function (): void {
    $this->postingInvoice->update(['total_amount' => '99999999.99']);
    $this->postingPayment->update(['payment_amount' => '99999999.98']);
    app(PaymentPostingService::class)->post($this->postingPayment);
    expect($this->postingInvoice->fresh()->payment_status)->toBe('partial');
    $lastCent = Payment::create(['team_id' => $this->postingTeam->id, 'invoice_id' => $this->postingInvoice->id, 'payment_date' => '2026-06-02', 'payment_amount' => '0.01']);
    app(PaymentPostingService::class)->post($lastCent);
    expect($this->postingInvoice->fresh()->payment_status)->toBe('paid');
});

it('excludes a foreign payment and a payment linked to an unposted journal from invoice status', function (): void {
    $journal = JournalEntry::factory()->create(['team_id' => $this->postingTeam->id, 'user_id' => $this->postingTeam->user_id]);
    $this->postingPayment->update(['journal_entry_id' => $journal->id]);
    $this->postingInvoice->recomputePaymentStatus();
    expect($this->postingInvoice->fresh()->payment_status)->toBe('pending');
    $this->postingPayment->update(['journal_entry_id' => null]);
    app(PaymentPostingService::class)->post($this->postingPayment);
    $this->postingPayment->update(['team_id' => null]);
    $this->postingInvoice->recomputePaymentStatus();
    expect($this->postingInvoice->fresh()->payment_status)->toBe('pending');
});
