<?php

declare(strict_types=1);

use App\Jobs\SyncPlaidTransactionsJob;
use App\Models\BankConnection;
use App\Models\BankFeedTransaction;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlaidService;
use App\Services\TeamManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['finance-suite.demo' => false, 'services.plaid.environment' => 'sandbox',
        'services.plaid.client_id' => 'synthetic-client', 'services.plaid.secret' => 'synthetic-secret']);
    $this->plaidOwner = User::factory()->create();
    $this->plaidTeam = app(TeamManagementService::class)->createPersonalTeamForUser($this->plaidOwner);
    $this->plaidCurrency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'is_default' => true]);
    $this->plaidConnection = BankConnection::create([
        'bank_id' => 'plaid', 'user_id' => $this->plaidOwner->id, 'team_id' => $this->plaidTeam->id,
        'plaid_access_token' => 'synthetic-access', 'plaid_item_id' => 'synthetic-item', 'status' => 'active',
    ]);
    $this->plaidRow = ['transaction_id' => 'plaid-owned-1', 'date' => '2026-10-07',
        'amount' => '-25.50', 'iso_currency_code' => 'USD', 'pending' => false, 'name' => 'Synthetic receipt', 'category' => ['Transfer', 'Deposit']];
});

function plaidSyncResponse(array $added = [], array $modified = [], array $removed = [], string $cursor = 'cursor-one', bool $more = false): array
{
    return ['added' => $added, 'modified' => $modified, 'removed' => $removed, 'next_cursor' => $cursor, 'has_more' => $more];
}

function executePlaidSync(BankConnection $connection): void
{
    (new SyncPlaidTransactionsJob((int) $connection->getKey()))->handle(app(PlaidService::class));
}

it('imports exact cents as the connection tenant and retries without duplicate records', function (): void {
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::response(plaidSyncResponse([$this->plaidRow]))]);
    executePlaidSync($this->plaidConnection);
    executePlaidSync($this->plaidConnection);
    $transaction = Transaction::sole();
    expect($transaction->amount)->toBe('25.50')->and($transaction->type)->toBe('credit')
        ->and($transaction->team_id)->toBe($this->plaidTeam->id)
        ->and($transaction->currency_id)->toBe($this->plaidCurrency->getKey())
        ->and($transaction->status)->toBe('posted')->and($transaction->category)->toBe('deposit')
        ->and($transaction->reconciled)->toBeFalse()
        ->and(BankFeedTransaction::sole()->raw_data)->toBe($this->plaidRow)
        ->and($this->plaidConnection->fresh()->plaid_cursor)->toBe('cursor-one');
});

it('follows every advancing provider page before committing the cursor', function (): void {
    $second = [...$this->plaidRow, 'transaction_id' => 'plaid-owned-2', 'amount' => '0.30', 'pending' => true, 'category' => []];
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::sequence()
        ->push(plaidSyncResponse([$this->plaidRow], cursor: 'page-one', more: true))
        ->push(plaidSyncResponse([$second], cursor: 'page-two'))]);
    executePlaidSync($this->plaidConnection);
    expect(Transaction::count())->toBe(2)->and($this->plaidConnection->fresh()->plaid_cursor)->toBe('page-two');
    $row = Transaction::where('external_id', 'plaid-owned-2')->sole();
    expect($row->amount)->toBe('0.30')->and($row->type)->toBe('debit')
        ->and($row->status)->toBe('pending')->and($row->category)->toBe('uncategorized');
    Http::assertSent(fn ($request): bool => ($request->data()['cursor'] ?? null) === 'page-one');
});

it('rolls back all rows and cursor when any provider amount date or state is malformed', function (array $change): void {
    $bad = [...$this->plaidRow, 'transaction_id' => 'bad-row', ...$change];
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::response(plaidSyncResponse([$this->plaidRow, $bad]))]);
    expect(fn () => executePlaidSync($this->plaidConnection))->toThrow(InvalidArgumentException::class);
    expect(Transaction::count())->toBe(0)->and(BankFeedTransaction::count())->toBe(0)
        ->and($this->plaidConnection->fresh()->plaid_cursor)->toBeNull();
})->with(['fractional cent' => [['amount' => '0.001']], 'exponent' => [['amount' => '1e2']],
    'invalid day' => [['date' => '2026-02-30']], 'timestamp' => [['date' => '2026-10-07 00:00:00']],
    'missing identity' => [['transaction_id' => '']], 'unknown state' => [['pending' => 'false']],
    'missing currency' => [['iso_currency_code' => null]], 'unknown currency' => [['iso_currency_code' => 'XYZ']],
    'unofficial currency' => [['unofficial_currency_code' => 'synthetic-token']]]);

it('does not advance an invalid or stationary pagination cursor', function (): void {
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::response([
        'added' => [$this->plaidRow], 'has_more' => true,
    ])]);
    expect(fn () => executePlaidSync($this->plaidConnection))->toThrow(InvalidArgumentException::class, 'pagination');
    expect(Transaction::count())->toBe(0)->and($this->plaidConnection->fresh()->plaid_cursor)->toBeNull();
});

it('protects reconciled money from modification and removal but permits identical replay', function (bool $remove): void {
    $initial = plaidSyncResponse([$this->plaidRow]);
    $rejected = $remove
        ? plaidSyncResponse(removed: [['transaction_id' => $this->plaidRow['transaction_id']]], cursor: 'forbidden')
        : plaidSyncResponse(modified: [[...$this->plaidRow, 'amount' => '99.00']], cursor: 'forbidden');
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::sequence()->push($initial)->push($initial)->push($rejected)]);
    executePlaidSync($this->plaidConnection);
    $transaction = Transaction::sole();
    $transaction->update(['reconciled' => true]);
    executePlaidSync($this->plaidConnection);
    expect(fn () => executePlaidSync($this->plaidConnection))->toThrow(LogicException::class, 'Reconciled');
    expect($transaction->fresh()->amount)->toBe('25.50')->and($transaction->fresh()->reconciled)->toBeTrue()
        ->and($this->plaidConnection->fresh()->plaid_cursor)->toBe('cursor-one');
})->with([false, true]);

it('updates and removes only its own unreconciled transaction', function (): void {
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::sequence()
        ->push(plaidSyncResponse([$this->plaidRow]))
        ->push(plaidSyncResponse(modified: [[...$this->plaidRow, 'amount' => '12.34']]))
        ->push(plaidSyncResponse(removed: [
            ['transaction_id' => 'unknown'], ['transaction_id' => $this->plaidRow['transaction_id']],
        ]))]);
    executePlaidSync($this->plaidConnection);
    executePlaidSync($this->plaidConnection);
    expect(Transaction::sole()->amount)->toBe('12.34');
    executePlaidSync($this->plaidConnection);
    expect(Transaction::count())->toBe(0)->and(BankFeedTransaction::count())->toBe(0);
});

it('rejects a provider identity already assigned to a foreign company', function (): void {
    $other = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($other);
    $foreign = Transaction::factory()->create(['team_id' => $team->id, 'external_id' => $this->plaidRow['transaction_id'], 'amount' => '88.88']);
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::response(plaidSyncResponse([$this->plaidRow]))]);
    expect(fn () => executePlaidSync($this->plaidConnection))->toThrow(LogicException::class, 'another company');
    expect(Transaction::count())->toBe(1)->and($foreign->fresh()->amount)->toBe('88.88')
        ->and($this->plaidConnection->fresh()->plaid_cursor)->toBeNull();
});

it('denies foreign and revoked identities before requesting provider data', function (string $actor): void {
    $other = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($other);
    if ($actor !== 'foreign') {
        $this->plaidTeam->users()->attach($other->id, ['role' => $actor === 'viewer' ? 'viewer' : 'accountant']);
        $other->switchTeam($this->plaidTeam);
        if ($actor === 'revoked') {
            $this->plaidTeam->users()->detach($other->id);
        }
    }
    $this->actingAs($other->fresh());
    expect(fn () => executePlaidSync($this->plaidConnection))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
    expect(Transaction::count())->toBe(0);
})->with(['foreign', 'viewer', 'revoked']);

it('permits current accounting members to synchronize the selected shared connection', function (string $role): void {
    $member = User::factory()->create();
    $this->plaidTeam->users()->attach($member->id, ['role' => $role]);
    $member->switchTeam($this->plaidTeam);
    $this->actingAs($member->fresh());
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::response(plaidSyncResponse([$this->plaidRow]))]);
    executePlaidSync($this->plaidConnection);
    expect(Transaction::sole()->team_id)->toBe($this->plaidTeam->id)
        ->and($this->plaidConnection->fresh()->plaid_cursor)->toBe('cursor-one');
    Http::assertSentCount(1);
})->with(['admin', 'accountant']);

it('skips inactive connections and denies demo missing-token orphaned and wrong-provider identities', function (string $state): void {
    if ($state === 'inactive') {
        $this->plaidConnection->update(['status' => 'inactive']);
        executePlaidSync($this->plaidConnection);
    } else {
        if ($state === 'demo') {
            config(['finance-suite.demo' => true]);
        }
        if ($state === 'token') {
            $this->plaidConnection->update(['plaid_access_token' => null]);
        }
        if ($state === 'orphan') {
            $this->plaidConnection->update(['team_id' => null]);
        }
        if ($state === 'provider') {
            $this->plaidConnection->update(['bank_id' => 'revolut', 'plaid_institution_id' => 'revolut']);
        }
        expect(fn () => executePlaidSync($this->plaidConnection))->toThrow(in_array($state, ['orphan', 'provider']) ? AuthorizationException::class : LogicException::class);
    }
    Http::assertNothingSent();
    expect(Transaction::count())->toBe(0);
})->with(['inactive', 'demo', 'token', 'orphan', 'provider']);

it('reauthenticates provider-rejected connections without leaking provider secrets into job logs', function (): void {
    Log::spy();
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::response([
        'error_code' => 'ITEM_LOGIN_REQUIRED', 'error_message' => 'synthetic-private-access-token',
    ], 400)]);
    expect(fn () => executePlaidSync($this->plaidConnection))->toThrow(RuntimeException::class, 'reauthentication');
    expect($this->plaidConnection->fresh()->status)->toBe('requires_reauth')->and(Transaction::count())->toBe(0);
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context): bool => $message === 'Plaid transaction sync job failed'
        && ! str_contains(json_encode($context), 'synthetic-private-access-token'));
    (new SyncPlaidTransactionsJob((int) $this->plaidConnection->id))->failed(new Exception('synthetic-private-access-token'));
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context): bool => $message === 'Plaid transaction sync job permanently failed'
        && ! array_key_exists('error', $context));
});

it('dispatches only active token-bearing connections from the CLI', function (): void {
    Bus::fake([SyncPlaidTransactionsJob::class]);
    $this->artisan('plaid:sync-transactions')->assertFailed();
    $this->artisan('plaid:sync-transactions', ['--connection' => 999999])->assertFailed();
    $this->plaidConnection->update(['status' => 'inactive']);
    $this->artisan('plaid:sync-transactions', ['--connection' => $this->plaidConnection->id])->assertFailed();
    $this->artisan('plaid:sync-transactions', ['--all' => true])->assertSuccessful();
    Bus::assertNothingDispatched();
    $this->plaidConnection->update(['status' => 'active']);
    $this->artisan('plaid:sync-transactions', ['--connection' => $this->plaidConnection->id])->assertSuccessful();
    $this->artisan('plaid:sync-transactions', ['--all' => true])->assertSuccessful();
    Bus::assertDispatched(SyncPlaidTransactionsJob::class, 2);
});
