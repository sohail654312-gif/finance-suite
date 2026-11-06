<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Bill;
use App\Models\CreditMemo;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\QboConnection;
use App\Models\SageConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\XeroConnection;
use App\Services\QuickBooksService;
use App\Services\SageService;
use App\Services\TeamManagementService;
use App\Services\XeroService;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/** @return array{object, object, int, int} */
function syncCoverageFixture(string $provider): array
{
    $owner = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $other = User::factory()->create();
    $otherTeam = app(TeamManagementService::class)->createPersonalTeamForUser($other);
    $configuration = [
        'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret',
        'redirect_uri' => 'https://app.test/callback', 'authorization_url' => 'https://provider.test/authorize',
        'token_url' => 'https://provider.test/token', 'api_base_url' => 'https://provider.test/'.$provider,
    ];
    config()->set('services.'.$provider, $configuration);
    [$connectionClass, $serviceClass, $identity] = match ($provider) {
        'sage' => [SageConnection::class, SageService::class, ['business_id' => 'synthetic-business']],
        'xero' => [XeroConnection::class, XeroService::class, ['tenant_id' => 'synthetic-tenant']],
        'qbo' => [QboConnection::class, QuickBooksService::class, ['realm_id' => 'synthetic-realm']],
    };
    $connection = $connectionClass::create($identity + [
        'user_id' => $owner->getKey(), 'team_id' => $team->getKey(), 'status' => 'active',
        'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
        'token_expires_at' => now()->addHour(),
    ]);

    return [app($serviceClass), $connection, (int) $team->getKey(), (int) $otherTeam->getKey()];
}

/** @return array<string,mixed> */
function syncCoverageResponse(string $provider, string $entity, array $rows): array
{
    return match ($provider) {
        'sage' => ['$items' => $rows],
        'xero' => [$entity => $rows],
        'qbo' => ['QueryResponse' => [$entity => $rows]],
    };
}

it('pushes complete accounting documents and persists acknowledged mappings for each provider', function (string $provider, bool $mapped): void {
    [$service, $connection, $teamId] = syncCoverageFixture($provider);
    $column = $provider.'_id';
    $customer = Customer::factory()->create(['team_id' => $teamId, $column => 'customer-owned']);
    $vendor = Vendor::factory()->create(['team_id' => $teamId, $column => 'vendor-owned']);
    $invoice = Invoice::factory()->create([
        'team_id' => $teamId, 'customer_id' => $customer->getKey(), 'invoice_date' => '2026-11-06', 'total_amount' => '125.45',
    ]);
    $documents = [
        'Invoice' => $invoice,
        'Estimate' => Estimate::factory()->create([
            'team_id' => $teamId, 'customer_id' => $customer->getKey(), 'estimate_date' => '2026-11-06',
            'expiration_date' => '2027-01-01', 'total_amount' => '125.45',
        ]),
        'CreditMemo' => CreditMemo::create([
            'team_id' => $teamId, 'customer_id' => $customer->getKey(), 'credit_memo_date' => '2026-11-06',
            'subtotal_amount' => '100.00', 'tax_amount' => '25.45', 'total_amount' => '125.45', 'status' => 'open',
        ]),
        'Customer' => $customer,
        'Vendor' => $vendor,
        'Account' => Account::factory()->create(['team_id' => $teamId, 'account_type' => 'expense', 'account_number' => 5600]),
        'Bill' => Bill::factory()->create([
            'team_id' => $teamId, 'vendor_id' => $vendor->getKey(), 'bill_date' => '2026-11-06',
            'due_date' => '2026-12-06', 'total_amount' => '125.45',
        ]),
        'Payment' => Payment::create([
            'team_id' => $teamId, 'invoice_id' => $invoice->getKey(), 'payment_date' => '2026-11-06', 'payment_amount' => '25.55',
        ]),
    ];
    $response = [];
    Http::fake(function (Request $request) use (&$response) {
        return Http::response($response);
    });
    foreach ($documents as $entity => $document) {
        $document->update([$column => $mapped ? 'existing-'.$entity : null]);
        if ($provider === 'qbo' && in_array($entity, ['Invoice', 'Account', 'Bill', 'Payment'], true)) {
            $document->update(['qbo_sync_token' => '7']);
        }
        $remoteId = 'acknowledged-'.$entity;
        $xeroEntity = match ($entity) {
            'Estimate' => ['Quotes', 'QuoteID'], 'CreditMemo' => ['CreditNotes', 'CreditNoteID'],
            'Customer', 'Vendor' => ['Contacts', 'ContactID'], 'Account' => ['Accounts', 'AccountID'],
            'Payment' => ['Payments', 'PaymentID'], default => ['Invoices', 'InvoiceID'],
        };
        $response = match ($provider) {
            'sage' => ['id' => $remoteId],
            'xero' => [$xeroEntity[0] => [[$xeroEntity[1] => $remoteId]]],
            'qbo' => [$entity => ['Id' => $remoteId, 'SyncToken' => '8']],
        };
        $result = $service->{'push'.$entity}($document, $connection);
        expect($result->is($document))->toBeTrue()
            ->and($document->fresh()->getAttribute($column))->toBe($remoteId)
            ->and((int) $document->fresh()->team_id)->toBe($teamId);
        $request = Http::recorded()->last()[0];
        expect($request->hasHeader('Authorization', 'Bearer synthetic-access'))->toBeTrue();
        $payload = $request->data();
        if ($provider === 'qbo') {
            if ($mapped) {
                expect($payload['Id'])->toBe('existing-'.$entity)->and($payload['sparse'])->toBeTrue();
            } else {
                expect($payload)->not->toHaveKey('sparse');
            }
            if (in_array($entity, ['Invoice', 'Estimate', 'CreditMemo', 'Bill'], true)) {
                expect((string) $payload['Line'][0]['Amount'])->toBe('125.45');
            }
            if ($entity === 'Payment') {
                expect((string) $payload['TotalAmt'])->toBe('25.55')
                    ->and($payload['Line'][0]['LinkedTxn'][0]['TxnId'])->toBe($invoice->qbo_id);
            }
        } elseif ($provider === 'xero') {
            expect($request->hasHeader('Xero-tenant-id', 'synthetic-tenant'))->toBeTrue();
            if ($entity === 'Account') {
                expect($request->method())->toBe($mapped ? 'POST' : 'PUT');
            }
            if (in_array($entity, ['Estimate', 'CreditMemo', 'Invoice', 'Bill'], true)) {
                expect((string) $payload[$xeroEntity[0]][0]['LineItems'][0]['UnitAmount'])->toBe('125.45');
            }
        } else {
            if ($entity === 'Estimate') {
                expect($payload['quote']['date'])->toBe('2026-11-06')
                    ->and($payload['quote']['expiry_date'])->toBe('2027-01-01');
            }
            if ($entity === 'CreditMemo') {
                expect((string) $payload['sales_credit_note']['credit_note_lines'][0]['unit_price'])->toBe('125.45');
            }
        }
    }
    Http::assertSentCount(8);
})->with(['sage', 'xero', 'qbo'])->with([false, true]);

it('imports quotes credits and contacts idempotently within their owning company', function (string $provider): void {
    [$service, $connection, $teamId, $otherTeamId] = syncCoverageFixture($provider);
    $column = $provider.'_id';
    $ownCustomer = Customer::factory()->create(['team_id' => $teamId, $column => 'contact-1']);
    $foreignCustomer = Customer::factory()->create(['team_id' => $otherTeamId, $column => 'contact-1', 'customer_name' => 'Foreign customer']);
    $foreignVendor = Vendor::factory()->create(['team_id' => $otherTeamId, $column => 'supplier-1', 'name' => 'Foreign vendor']);
    $response = [];
    Http::fake(function (Request $request) use (&$response) {
        return Http::response($response);
    });
    $customerRow = match ($provider) {
        'sage' => ['id' => 'contact-1', 'name' => 'Imported customer', 'email' => 'local@example.test', 'telephone' => '111', 'address' => ['city' => 'Local City']],
        'xero' => ['ContactID' => 'contact-1', 'Name' => 'Imported customer', 'EmailAddress' => 'local@example.test', 'IsCustomer' => true, 'IsSupplier' => false, 'Phones' => [['PhoneNumber' => '111']], 'Addresses' => [['City' => 'Local City']]],
        'qbo' => ['Id' => 'contact-1', 'DisplayName' => 'Imported customer', 'PrimaryEmailAddr' => ['Address' => 'local@example.test'], 'PrimaryPhone' => ['FreeFormNumber' => '111'], 'BillAddr' => ['City' => 'Local City']],
    };
    $vendorRow = match ($provider) {
        'sage' => ['id' => 'supplier-1', 'name' => 'Imported vendor', 'email' => 'supplier@example.test', 'telephone' => '222', 'address' => ['address_line_1' => 'Supplier Street']],
        'xero' => ['ContactID' => 'supplier-1', 'Name' => 'Imported vendor', 'IsCustomer' => false, 'IsSupplier' => true, 'EmailAddress' => 'supplier@example.test', 'Phones' => [['PhoneNumber' => '222']], 'Addresses' => [['AddressLine1' => 'Supplier Street']]],
        'qbo' => ['Id' => 'supplier-1', 'DisplayName' => 'Imported vendor', 'PrimaryEmailAddr' => ['Address' => 'supplier@example.test'], 'PrimaryPhone' => ['FreeFormNumber' => '222'], 'BillAddr' => ['Line1' => 'Supplier Street']],
    };
    $response = syncCoverageResponse($provider, $provider === 'xero' ? 'Contacts' : 'Customer', $provider === 'xero' ? [$customerRow, [], $vendorRow] : [$customerRow, []]);
    $service->pullCustomers($connection);
    $service->pullCustomers($connection);
    expect(Customer::where('team_id', $teamId)->where($column, 'contact-1')->count())->toBe(1)
        ->and($ownCustomer->fresh()->customer_name)->toBe('Imported customer')
        ->and($ownCustomer->fresh()->customer_city)->toBe('Local City')
        ->and($foreignCustomer->fresh()->customer_name)->toBe('Foreign customer');
    $response = syncCoverageResponse($provider, $provider === 'xero' ? 'Contacts' : 'Vendor', $provider === 'xero' ? [$vendorRow, [], $customerRow] : [$vendorRow, []]);
    $service->pullVendors($connection);
    $service->pullVendors($connection);
    $ownVendor = Vendor::where('team_id', $teamId)->where($column, 'supplier-1')->firstOrFail();
    expect($ownVendor->name)->toBe('Imported vendor')->and($ownVendor->phone)->toBe('222')
        ->and($foreignVendor->fresh()->name)->toBe('Foreign vendor');

    $contact = match ($provider) {
        'sage' => ['contact_id' => 'contact-1'],
        'xero' => ['Contact' => ['ContactID' => 'contact-1']],
        'qbo' => ['CustomerRef' => ['value' => 'contact-1']],
    };
    $quote = match ($provider) {
        'sage' => ['id' => 'quote-1', 'reference' => 'QUOTE-LOCAL', 'date' => '2026-11-06', 'expiry_date' => '2027-01-01', 'total_amount' => '120.30', 'net_amount' => '100.25', 'tax_amount' => '20.05'],
        'xero' => ['QuoteID' => 'quote-1', 'QuoteNumber' => 'QUOTE-LOCAL', 'Date' => '2026-11-06', 'ExpiryDate' => '2027-01-01', 'Total' => '120.30', 'SubTotal' => '100.25', 'TotalTax' => '20.05', 'Status' => 'DRAFT'],
        'qbo' => ['Id' => 'quote-1', 'DocNumber' => 'QUOTE-LOCAL', 'TxnDate' => '2026-11-06', 'ExpirationDate' => '2027-01-01', 'TotalAmt' => '120.30', 'CustomerMemo' => ['value' => 'draft']],
    };
    $credit = match ($provider) {
        'sage' => ['id' => 'credit-1', 'reference' => 'CREDIT-LOCAL', 'date' => '2026-11-06', 'total_amount' => '12.30', 'net_amount' => '10.25', 'tax_amount' => '2.05'],
        'xero' => ['CreditNoteID' => 'credit-1', 'CreditNoteNumber' => 'CREDIT-LOCAL', 'Date' => '2026-11-06', 'Total' => '12.30', 'SubTotal' => '10.25', 'TotalTax' => '2.05'],
        'qbo' => ['Id' => 'credit-1', 'DocNumber' => 'CREDIT-LOCAL', 'TxnDate' => '2026-11-06', 'TotalAmt' => '12.30'],
    };
    foreach ([['Estimates', 'Quotes', 'Estimate', $quote, Estimate::class, 'quote-1'], ['CreditMemos', 'CreditNotes', 'CreditMemo', $credit, CreditMemo::class, 'credit-1']] as [$method, $xeroKey, $qboKey, $row, $model, $externalId]) {
        $response = syncCoverageResponse($provider, $provider === 'xero' ? $xeroKey : $qboKey, [$row + $contact, []]);
        expect($service->{'pull'.$method}($connection))->toBe(2);
        expect($service->{'pull'.$method}($connection))->toBe(2);
        $local = $model::where('team_id', $teamId)->where($column, $externalId)->firstOrFail();
        expect($local->customer_id)->toBe($ownCustomer->getKey())
            ->and($local->total_amount)->toBe($externalId === 'quote-1' ? '120.30' : '12.30')
            ->and($model::where($column, $externalId)->count())->toBe(1);
    }
    expect(Customer::where('team_id', $teamId)->count())->toBe(1);
})->with(['sage', 'xero', 'qbo']);

it('maps provider account classifications and fallback codes without changing another company', function (string $provider): void {
    [$service, $connection, $teamId, $otherTeamId] = syncCoverageFixture($provider);
    $column = $provider.'_id';
    $foreign = Account::factory()->create([
        'team_id' => $otherTeamId, $column => '701', 'account_number' => 4401, 'account_name' => 'Private foreign account', 'balance' => '9.99',
    ]);
    $expectedTypes = ['liability', 'equity', 'revenue', 'expense', 'asset', 'asset'];
    $rows = [];
    foreach ($expectedTypes as $index => $expectedType) {
        $id = (string) (701 + $index);
        $rows[] = match ($provider) {
            'sage' => ['id' => $id, 'displayed_as' => 'Imported account '.$index, 'nominal_code' => $index === 0 ? '4401' : 'not-a-number', 'ledger_account_type' => ['name' => ['Current Liability', 'Equity', 'Income', 'Cost of Sales', 'Asset', 'Unknown'][$index]], 'is_active' => $index !== 1],
            'xero' => ['AccountID' => $id, 'Name' => 'Imported account '.$index, 'Code' => $index === 0 ? '4401' : 'not-a-number', 'Class' => ['LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE', 'ASSET', 'Unknown'][$index]],
            'qbo' => ['Id' => $id, 'Name' => 'Imported account '.$index, 'AcctNum' => $index === 0 ? '4401' : 'not-a-number', 'Classification' => ['Liability', 'Equity', 'Revenue', 'Expense', 'Asset', 'Unknown'][$index], 'SyncToken' => '3'],
        };
    }
    if ($provider === 'sage') {
        $rows[] = [];
    }
    Http::fake(['*' => Http::response(syncCoverageResponse($provider, $provider === 'xero' ? 'Accounts' : 'Account', $rows))]);
    $service->pullAccounts($connection);
    $service->pullAccounts($connection);
    foreach ($expectedTypes as $index => $expectedType) {
        $local = Account::where('team_id', $teamId)->where($column, (string) (701 + $index))->firstOrFail();
        expect($local->account_type)->toBe($expectedType)->and($local->account_name)->toBe('Imported account '.$index);
        if ($index === 0) {
            expect((int) $local->account_number)->toBe(4401);
        } else {
            expect((int) $local->account_number)->toBeGreaterThanOrEqual(9000);
        }
    }
    expect(Account::where('team_id', $teamId)->count())->toBe(6)
        ->and($foreign->fresh()->account_name)->toBe('Private foreign account')
        ->and($foreign->fresh()->balance)->toBe('9.99')
        ->and($connection->fresh()->last_synced_at)->not->toBeNull();
})->with(['sage', 'xero', 'qbo']);

it('runs the complete nine-entity sync contract without side effects for an empty provider company', function (string $provider): void {
    [$service, $connection, $teamId] = syncCoverageFixture($provider);
    Http::fake(['*' => Http::response([])]);

    $counts = $service->sync($connection);

    expect($counts)->toBe(array_fill_keys(['customers', 'vendors', 'accounts', 'invoices', 'bills', 'payments', 'estimates', 'credit_memos', 'transactions'], 0))
        ->and(Invoice::where('team_id', $teamId)->count())->toBe(0)
        ->and(Bill::where('team_id', $teamId)->count())->toBe(0)
        ->and(Payment::where('team_id', $teamId)->count())->toBe(0)
        ->and(Transaction::where('team_id', $teamId)->count())->toBe(0)
        ->and($connection->fresh()->last_synced_at)->not->toBeNull();
    Http::assertSentCount($provider === 'qbo' ? 11 : 9);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->with(['sage', 'xero', 'qbo']);

it('rolls back quote and credit batches including generated contacts when a later row is invalid', function (string $provider, string $kind): void {
    [$service, $connection, $teamId] = syncCoverageFixture($provider);
    $idKey = match ($provider) {
        'sage' => 'id', 'xero' => $kind === 'Estimates' ? 'QuoteID' : 'CreditNoteID', default => 'Id'
    };
    $dateKey = match ($provider) {
        'sage' => 'date', 'xero' => 'Date', default => 'TxnDate'
    };
    $amountKey = match ($provider) {
        'sage' => 'total_amount', 'xero' => 'Total', default => 'TotalAmt'
    };
    $entity = $kind === 'Estimates' ? ($provider === 'xero' ? 'Quotes' : 'Estimate') : ($provider === 'xero' ? 'CreditNotes' : 'CreditMemo');
    Http::fake(['*' => Http::response(syncCoverageResponse($provider, $entity, [
        [$idKey => 'valid-first', $dateKey => '2026-11-06', $amountKey => '45.67'],
        [$idKey => 'invalid-later', $dateKey => 'not-a-date', $amountKey => '89.01'],
    ]))]);

    expect(fn () => $service->{'pull'.$kind}($connection))->toThrow(InvalidFormatException::class);
    $model = $kind === 'Estimates' ? Estimate::class : CreditMemo::class;
    expect($model::where('team_id', $teamId)->count())->toBe(0)
        ->and(Customer::where('team_id', $teamId)->count())->toBe(0)
        ->and($connection->fresh()->last_synced_at)->toBeNull();
})->with(['sage', 'xero', 'qbo'])->with(['Estimates', 'CreditMemos']);

it('does not attach payments with missing or foreign references to unsynced local invoices', function (string $provider): void {
    [$service, $connection, $teamId, $otherTeamId] = syncCoverageFixture($provider);
    $column = $provider.'_id';
    $local = Invoice::factory()->create(['team_id' => $teamId, $column => null]);
    $foreign = Invoice::factory()->create(['team_id' => $otherTeamId, $column => 'foreign-invoice']);
    $valid = Invoice::factory()->create(['team_id' => $teamId, $column => 'owned-invoice']);
    $rows = [];
    foreach ([null, '', 'foreign-invoice', 'owned-invoice'] as $index => $invoiceRef) {
        $rows[] = match ($provider) {
            'sage' => ['id' => 'payment-'.$index, 'invoice_id' => $invoiceRef, 'total_amount' => '12.34', 'date' => '2026-11-06'],
            'xero' => ['PaymentID' => 'payment-'.$index, 'Invoice' => ['InvoiceID' => $invoiceRef], 'Amount' => '12.34', 'Date' => '2026-11-06'],
            'qbo' => ['Id' => 'payment-'.$index, 'Line' => [['LinkedTxn' => [['TxnId' => $invoiceRef]]]], 'TotalAmt' => '12.34', 'TxnDate' => '2026-11-06'],
        };
    }
    Http::fake(['*' => Http::response(syncCoverageResponse($provider, $provider === 'xero' ? 'Payments' : 'Payment', $rows))]);
    $service->pullPayments($connection);
    $service->pullPayments($connection);

    expect(Payment::where('team_id', $teamId)->count())->toBe(1)
        ->and(Payment::where($column, 'payment-3')->firstOrFail()->invoice_id)->toBe($valid->getKey())
        ->and($local->payments()->count())->toBe(0)
        ->and($foreign->payments()->count())->toBe(0);
})->with(['sage', 'xero', 'qbo']);

it('preserves local document mapping and totals when an outbound provider request fails', function (string $provider): void {
    [$service, $connection, $teamId] = syncCoverageFixture($provider);
    $column = $provider.'_id';
    $customer = Customer::factory()->create(['team_id' => $teamId]);
    $credit = CreditMemo::create([
        'team_id' => $teamId, 'customer_id' => $customer->getKey(), 'credit_memo_date' => '2026-11-06',
        'subtotal_amount' => '100.00', 'total_amount' => '100.00', $column => 'original-mapping',
    ]);
    Http::fake(['*' => Http::response(['error' => 'synthetic unavailable'], 503)]);

    expect(fn () => $service->pushCreditMemo($credit, $connection))->toThrow(RequestException::class);
    expect($credit->fresh()->getAttribute($column))->toBe('original-mapping')
        ->and($credit->fresh()->total_amount)->toBe('100.00');
    Http::assertSentCount(1);
})->with(['sage', 'xero', 'qbo']);

it('imports each bank transaction direction with stable identity and no posting or reconciliation', function (string $provider): void {
    [$service, $connection, $teamId] = syncCoverageFixture($provider);
    $column = $provider.'_id';
    $account = Account::factory()->create(['team_id' => $teamId, $column => 'bank-local']);
    Http::fake(function (Request $request) use ($provider) {
        if ($provider === 'qbo') {
            $entity = str_replace('select * from ', '', $request->data()['query']);

            return Http::response(['QueryResponse' => [$entity => [[
                'Id' => 'bank-'.$entity, 'TotalAmt' => '25.50', 'TransferAmt' => '25.50', 'TxnDate' => '2026-11-06',
                'AccountRef' => ['value' => 'bank-local'], 'Memo' => 'Synthetic '.$entity,
            ]]]]);
        }
        $rows = $provider === 'sage' ? [
            ['id' => 'bank-pay', 'amount' => '25.50', 'transaction_type' => 'payment', 'date' => '2026-11-06', 'bank_account_id' => 'bank-local'],
            ['id' => 'bank-receive', 'amount' => '35.50', 'transaction_type' => 'receive', 'date' => '2026-11-06', 'bank_account_id' => 'bank-local'],
        ] : [
            ['BankTransactionID' => 'bank-pay', 'Total' => '25.50', 'Type' => 'SPEND', 'Date' => '2026-11-06', 'BankAccount' => ['AccountID' => 'bank-local']],
            ['BankTransactionID' => 'bank-receive', 'Total' => '35.50', 'Type' => 'RECEIVE', 'Date' => '2026-11-06', 'BankAccount' => ['AccountID' => 'bank-local']],
        ];

        return Http::response(syncCoverageResponse($provider, 'BankTransactions', $rows));
    });
    $service->pullTransactions($connection);
    $service->pullTransactions($connection);
    $transactions = Transaction::where('team_id', $teamId)->get();
    expect($transactions)->toHaveCount($provider === 'qbo' ? 3 : 2)
        ->and($transactions->where('amount', '-25.50'))->toHaveCount(1)
        ->and($transactions->pluck('account_id')->unique()->all())->toBe([$account->getKey()])
        ->and($transactions->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($transactions->where('reconciled', true))->toHaveCount(0);
})->with(['sage', 'xero', 'qbo']);
