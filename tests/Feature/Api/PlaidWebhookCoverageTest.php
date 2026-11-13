<?php

declare(strict_types=1);

use App\Jobs\SyncPlaidTransactionsJob;
use App\Models\BankConnection;
use App\Models\OutboundPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlaidService;
use App\Services\TeamManagementService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Bus::fake();
    $this->plaidWebhookConnection = plaidWebhookCoverageConnection('synthetic-plaid-item');
    $this->plaidWebhookService = $this->mock(PlaidService::class);
});

function plaidWebhookCoverageConnection(string $itemId): BankConnection
{
    $user = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($user);

    return BankConnection::query()->create([
        'user_id' => $user->id, 'team_id' => $user->fresh()->current_team_id,
        'bank_id' => 'synthetic-plaid-institution', 'institution_name' => 'Synthetic bank',
        'plaid_item_id' => $itemId, 'plaid_access_token' => 'synthetic-private-access-token',
        'status' => 'active',
    ]);
}

function plaidCoverageWebhook(string $type, string $code, array $overrides = []): array
{
    return array_replace([
        'webhook_type' => $type, 'webhook_code' => $code, 'item_id' => 'synthetic-plaid-item',
    ], $overrides);
}

it('dispatches each verified transaction notification only to its matching item', function (string $code): void {
    $foreign = plaidWebhookCoverageConnection('synthetic-other-item');
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook('TRANSACTIONS', $code))->assertOk()->assertJsonPath('success', true);
    Bus::assertDispatched(SyncPlaidTransactionsJob::class, fn (SyncPlaidTransactionsJob $job): bool => $job->connectionId === $this->plaidWebhookConnection->id);
    Bus::assertDispatchedTimes(SyncPlaidTransactionsJob::class, 1);
    expect($foreign->fresh()->status)->toBe('active')->and(Transaction::count())->toBe(0)->and(OutboundPayment::count())->toBe(0);
    Http::assertNothingSent();
})->with(['SYNC_UPDATES_AVAILABLE', 'INITIAL_UPDATE', 'HISTORICAL_UPDATE', 'DEFAULT_UPDATE']);

it('denies unsigned transaction and item notifications before any dispatch or status mutation', function (string $type, string $code): void {
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(false);
    $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook($type, $code, ['error' => ['error_code' => 'ITEM_LOCKED']]))->assertUnauthorized()->assertJsonPath('success', false);
    Bus::assertNothingDispatched();
    expect($this->plaidWebhookConnection->fresh()->status)->toBe('active')->and(Transaction::count())->toBe(0);
})->with([['TRANSACTIONS', 'SYNC_UPDATES_AVAILABLE'], ['ITEM', 'ERROR'], ['ITEM', 'USER_PERMISSION_REVOKED']]);

it('does not guess a company for unknown or ambiguous item identities', function (string $type, string $boundary): void {
    if ($boundary === 'ambiguous') {
        plaidWebhookCoverageConnection($this->plaidWebhookConnection->plaid_item_id);
        $item = $this->plaidWebhookConnection->plaid_item_id;
    } else {
        $item = 'synthetic-missing-item';
    }
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    $response = $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook($type, $type === 'ITEM' ? 'ERROR' : 'DEFAULT_UPDATE', ['item_id' => $item, 'error' => ['error_code' => 'ITEM_LOCKED']]));
    $response->assertStatus($boundary === 'ambiguous' ? 500 : 200);
    expect($this->plaidWebhookConnection->fresh()->status)->toBe('active')->and(Transaction::count())->toBe(0);
    Bus::assertNothingDispatched();
})->with([['TRANSACTIONS', 'missing'], ['ITEM', 'missing'], ['TRANSACTIONS', 'ambiguous'], ['ITEM', 'ambiguous']]);

it('ignores inactive and non-Plaid connection notifications without scheduling financial imports', function (string $boundary): void {
    $changes = match ($boundary) {
        'inactive' => ['status' => 'revoked'],
        'provider' => ['bank_id' => 'revolut'],
        default => ['plaid_access_token' => null],
    };
    $this->plaidWebhookConnection->update($changes);
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook('TRANSACTIONS', 'DEFAULT_UPDATE'))->assertOk();
    Bus::assertNothingDispatched();
    expect(Transaction::count())->toBe(0);
})->with(['inactive', 'provider', 'token']);

it('records a removed-transactions count without deleting recognized financial history', function (): void {
    Log::spy();
    $transaction = Transaction::query()->create([
        'team_id' => $this->plaidWebhookConnection->team_id, 'bank_connection_id' => $this->plaidWebhookConnection->id,
        'external_id' => 'synthetic-removed-transaction', 'transaction_date' => '2026-11-01',
        'amount' => '50.25', 'type' => 'debit', 'status' => 'posted', 'reconciled' => true,
    ]);
    $original = $transaction->refresh()->getRawOriginal();
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook('TRANSACTIONS', 'TRANSACTIONS_REMOVED', ['removed_transactions' => ['synthetic-removed-transaction', 'synthetic-other-transaction']]))->assertOk();
    expect($transaction->fresh()->getRawOriginal())->toBe($original);
    Log::shouldHaveReceived('info', ['Transactions removed', ['connection_id' => $this->plaidWebhookConnection->id, 'count' => 2]])->once();
    Bus::assertNothingDispatched();
});

it('does not authorize financial effects for unknown transaction and item event codes', function (string $type): void {
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook($type, 'SYNTHETIC_UNKNOWN_CODE'))->assertOk();
    Bus::assertNothingDispatched();
    expect($this->plaidWebhookConnection->fresh()->status)->toBe('active')->and(Transaction::count())->toBe(0);
})->with(['TRANSACTIONS', 'ITEM']);

it('applies verified item error state transitions and omits provider error messages from logs', function (string $errorCode, string $status): void {
    Log::spy();
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook('ITEM', 'ERROR', ['error' => ['error_code' => $errorCode, 'error_message' => 'synthetic-private-access-token']]))->assertOk();
    expect($this->plaidWebhookConnection->fresh()->status)->toBe($status)->and(Transaction::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->with('Item error received', ['connection_id' => $this->plaidWebhookConnection->id, 'error_code' => $errorCode]);
    Bus::assertNothingDispatched();
})->with([['ITEM_LOGIN_REQUIRED', 'requires_reauth'], ['INVALID_CREDENTIALS', 'requires_reauth'], ['ITEM_LOCKED', 'locked'], ['SYNTHETIC_UNKNOWN_ERROR', 'active']]);

it('does not invent a status change when an item error contains no error object', function (): void {
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook('ITEM', 'ERROR'))->assertOk();
    expect($this->plaidWebhookConnection->fresh()->status)->toBe('active');
    Bus::assertNothingDispatched();
});

it('handles item lifecycle notifications without posting financial transactions', function (string $code, string $status): void {
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook('ITEM', $code))->assertOk();
    expect($this->plaidWebhookConnection->fresh()->status)->toBe($status)->and(Transaction::count())->toBe(0);
    Bus::assertNothingDispatched();
})->with([['WEBHOOK_UPDATE_ACKNOWLEDGED', 'active'], ['PENDING_EXPIRATION', 'active'], ['USER_PERMISSION_REVOKED', 'revoked']]);

it('handles authentication assets and future webhook families without logging raw payloads', function (string $type): void {
    Log::spy();
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    $payload = plaidCoverageWebhook($type, 'SYNTHETIC_NOTIFICATION', ['access_token' => 'synthetic-private-access-token', 'financial_records' => ['private' => 'synthetic-record']]);
    $this->postJson('/api/webhooks/plaid', $payload)->assertOk();
    if ($type === 'AUTH' || $type === 'ASSETS') {
        Log::shouldHaveReceived('info', [$type === 'AUTH' ? 'Auth webhook received' : 'Assets webhook received', ['code' => 'SYNTHETIC_NOTIFICATION']])->once();
    }
    Bus::assertNothingDispatched();
    expect(Transaction::count())->toBe(0)->and(OutboundPayment::count())->toBe(0);
    Http::assertNothingSent();
})->with(['AUTH', 'ASSETS', 'FUTURE_FAMILY']);

it('rejects malformed verified payloads before dispatch or connection mutation', function (array $overrides): void {
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook('ITEM', 'ERROR', $overrides))->assertUnprocessable()->assertJsonPath('message', 'Invalid webhook payload');
    expect($this->plaidWebhookConnection->fresh()->status)->toBe('active')->and(Transaction::count())->toBe(0);
    Bus::assertNothingDispatched();
})->with([
    [['webhook_type' => ['invalid']]], [['webhook_type' => null]], [['webhook_code' => ['invalid']]],
    [['webhook_code' => '']], [['item_id' => null]], [['item_id' => ['invalid']]],
    [['error' => 'synthetic-malformed-error']], [['error' => ['error_code' => ['invalid']]]],
    [['removed_transactions' => 'invalid']], [['removed_transactions' => ['a' => 'invalid-list']]],
    [['removed_transactions' => [['invalid']]]], [['removed_transactions' => array_fill(0, 1001, 'synthetic-id')]],
]);

it('keeps a dispatch failure retryable and redacts exception traces and messages', function (): void {
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    Log::spy();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->with(Mockery::type(SyncPlaidTransactionsJob::class))->andThrow(new RuntimeException('synthetic-private-queue-secret'));
    $this->app->instance(Dispatcher::class, $dispatcher);
    $response = $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook('TRANSACTIONS', 'SYNC_UPDATES_AVAILABLE'))->assertStatus(500)->assertJsonPath('success', false);
    expect($response->getContent())->not->toContain('synthetic-private-queue-secret');
    Log::shouldHaveReceived('error')->once()->with('Plaid webhook processing failed', ['exception_class' => RuntimeException::class]);
    expect($this->plaidWebhookConnection->fresh()->status)->toBe('active')->and(Transaction::count())->toBe(0);
});

it('keeps a connection storage failure retryable without false status acknowledgement', function (): void {
    $this->plaidWebhookService->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
    BankConnection::updating(fn () => throw new RuntimeException('synthetic-private-storage-secret'));
    $response = $this->postJson('/api/webhooks/plaid', plaidCoverageWebhook('ITEM', 'USER_PERMISSION_REVOKED'))->assertStatus(500)->assertJsonPath('success', false);
    expect($response->getContent())->not->toContain('synthetic-private-storage-secret');
    expect($this->plaidWebhookConnection->fresh()->status)->toBe('active');
    Bus::assertNothingDispatched();
});
