<?php

declare(strict_types=1);

use App\Models\ApprovalRule;
use App\Models\BankAccountBalance;
use App\Models\BankConnection;
use App\Models\BankFeedTransaction;
use App\Models\Currency;
use App\Models\OutboundPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RevolutService;
use App\Services\TeamManagementService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::flush();
    Notification::fake();
    config()->set('services.revolut', [
        'environment' => 'sandbox', 'client_id' => 'synthetic-client',
        'client_secret' => 'synthetic-secret', 'redirect_uri' => 'https://app.test/api/revolut/callback',
        'webhook_secret' => 'synthetic-signing-secret',
    ]);
    $this->revolutOwner = revolutCoverageUser();
    Sanctum::actingAs($this->revolutOwner, ['payments:write']);
    $this->revolutService = $this->mock(RevolutService::class);
    Currency::query()->update(['is_default' => false]);
    Currency::query()->updateOrCreate(['code' => 'GBP'], ['name' => 'Pound', 'symbol' => 'GBP', 'is_default' => true]);
});

function revolutCoverageUser(): User
{
    $user = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($user);

    return $user->fresh();
}

function revolutCoverageConnection(User $user, array $overrides = []): BankConnection
{
    return BankConnection::query()->create(array_replace([
        'user_id' => $user->id, 'team_id' => $user->current_team_id,
        'bank_id' => 'revolut', 'institution_name' => 'Synthetic Revolut',
        'status' => 'active', 'revolut_access_token' => 'synthetic-access-token',
        'revolut_refresh_token' => 'synthetic-refresh-token', 'revolut_token_expires_at' => now()->addHour(),
    ], $overrides));
}

function revolutCoverageRow(array $overrides = []): array
{
    return array_replace([
        'id' => 'synthetic-transaction', 'state' => 'completed', 'type' => 'card_payment',
        'created_at' => '2026-11-01T23:30:00-05:00', 'reference' => 'Synthetic purchase',
        'legs' => [['amount' => '-25.50', 'currency' => 'GBP', 'account_id' => 'synthetic-account']],
    ], $overrides);
}

function revolutCoveragePayment(array $overrides = []): array
{
    return array_replace([
        'idempotency_key' => 'synthetic-payment-key', 'account_id' => 'synthetic-account',
        'receiver' => ['counterparty_id' => 'synthetic-counterparty'], 'amount' => '25.50',
        'currency' => 'GBP', 'reference' => 'Synthetic supplier invoice',
    ], $overrides);
}

function revolutCoverageStateKey(User $user): string
{
    return 'revolut_oauth_state:'.$user->id.':'.$user->current_team_id;
}

it('connects Revolut with single-use company-bound state and encrypted credentials', function (): void {
    $this->revolutService->shouldReceive('getAuthorizationUrl')->once()->with(Mockery::on(fn ($state): bool => strlen($state) === 40))->andReturn('https://sandbox.example.test/revolut');
    $this->getJson('/api/revolut/authorize')->assertOk()->assertJsonPath('authorization_url', 'https://sandbox.example.test/revolut');
    $state = Cache::get(revolutCoverageStateKey($this->revolutOwner));
    $this->revolutService->shouldReceive('exchangeAuthorizationCode')->once()->with('synthetic-code')->andReturn(['access_token' => 'synthetic-new-access', 'refresh_token' => 'synthetic-new-refresh', 'expires_in' => 3600]);
    $response = $this->postJson('/api/revolut/callback', ['code' => 'synthetic-code', 'state' => $state]);
    $response->assertCreated()->assertJsonPath('connection.status', 'active');
    expect($response->getContent())->not->toContain('synthetic-new-access', 'synthetic-new-refresh');
    $connection = BankConnection::sole();
    expect($connection->team_id)->toBe($this->revolutOwner->current_team_id)
        ->and($connection->revolut_access_token)->toBe('synthetic-new-access')
        ->and($connection->getRawOriginal('revolut_access_token'))->not->toBe('synthetic-new-access')
        ->and($connection->revolut_token_expires_at->isFuture())->toBeTrue();
    $this->postJson('/api/revolut/callback', ['code' => 'synthetic-code', 'state' => $state])->assertUnprocessable();
    expect(BankConnection::count())->toBe(1);
});

it('connects credentials without optional refresh or expiry fields', function (): void {
    Cache::put(revolutCoverageStateKey($this->revolutOwner), 'state');
    $this->revolutService->shouldReceive('exchangeAuthorizationCode')->once()->andReturn(['access_token' => 'synthetic-access']);
    $this->postJson('/api/revolut/callback', ['code' => 'code', 'state' => 'state'])->assertCreated();
    expect(BankConnection::sole()->revolut_refresh_token)->toBeNull()
        ->and(BankConnection::sole()->revolut_token_expires_at)->toBeNull();
});

it('rejects malformed OAuth inputs and a state initiated for another company', function (): void {
    $this->revolutService->shouldNotReceive('exchangeAuthorizationCode');
    $this->postJson('/api/revolut/callback', [])->assertStatus(400);
    $this->postJson('/api/revolut/callback', ['code' => ['bad'], 'state' => 'state'])->assertUnprocessable();
    $this->postJson('/api/revolut/callback', ['code' => 'code', 'state' => ['bad']])->assertUnprocessable();
    Cache::put(revolutCoverageStateKey($this->revolutOwner), 'original-state');
    $other = revolutCoverageUser();
    $other->currentTeam->users()->attach($this->revolutOwner->id, ['role' => 'admin']);
    $this->revolutOwner->switchTeam($other->currentTeam);
    $this->postJson('/api/revolut/callback', ['code' => 'code', 'state' => 'original-state'])->assertUnprocessable();
    expect(BankConnection::count())->toBe(0);
});

it('rejects invalid token responses without retaining partial connections', function (array $tokens): void {
    Cache::put(revolutCoverageStateKey($this->revolutOwner), 'state');
    $this->revolutService->shouldReceive('exchangeAuthorizationCode')->once()->andReturn($tokens);
    $this->postJson('/api/revolut/callback', ['code' => 'code', 'state' => 'state'])->assertStatus(500)->assertJsonPath('message', 'Failed to connect Revolut account');
    expect(BankConnection::count())->toBe(0);
})->with([[[]], [['access_token' => '']], [['access_token' => 'token', 'refresh_token' => ['invalid']]], [['access_token' => 'token', 'expires_in' => -1]]]);

it('redacts authorization and token exchange failures', function (string $operation): void {
    Log::spy();
    if ($operation === 'authorize') {
        $this->revolutService->shouldReceive('getAuthorizationUrl')->once()->andThrow(new RuntimeException('private-provider-secret'));
        $response = $this->getJson('/api/revolut/authorize');
    } else {
        Cache::put(revolutCoverageStateKey($this->revolutOwner), 'state');
        $this->revolutService->shouldReceive('exchangeAuthorizationCode')->once()->andThrow(new RuntimeException('private-provider-secret'));
        $response = $this->postJson('/api/revolut/callback', ['code' => 'code', 'state' => 'state']);
    }
    $response->assertStatus(500);
    expect($response->getContent())->not->toContain('private-provider-secret');
    Log::shouldHaveReceived('error')->once()->with(Mockery::type('string'), Mockery::on(fn ($context): bool => ! str_contains(json_encode($context), 'private-provider-secret') && $context['exception_class'] === RuntimeException::class));
    expect(BankConnection::count())->toBe(0);
})->with(['authorize', 'callback']);

it('lists only owned Revolut connections without provider credentials', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    revolutCoverageConnection($this->revolutOwner, ['bank_id' => 'wise']);
    revolutCoverageConnection(revolutCoverageUser());
    $response = $this->getJson('/api/revolut/connections')->assertOk()->assertJsonCount(1, 'connections')->assertJsonPath('connections.0.id', $connection->id);
    expect($response->getContent())->not->toContain('synthetic-access-token', 'synthetic-refresh-token');
});

it('denies cross-company and wrong-provider connection operations', function (string $verb, string $suffix, string $boundary): void {
    $owner = $boundary === 'company' ? revolutCoverageUser() : $this->revolutOwner;
    $connection = revolutCoverageConnection($owner, $boundary === 'provider' ? ['bank_id' => 'wise'] : []);
    $payload = $suffix === '/bulk-pay' ? ['idempotency_key' => 'bulk', 'title' => 'Synthetic batch', 'payments' => [revolutCoveragePayment()]] : revolutCoveragePayment();
    $uri = '/api/revolut/connections/'.$connection->id.$suffix;
    $response = $verb === 'get' ? $this->getJson($uri) : $this->{$verb.'Json'}($uri, $payload);
    $response->assertForbidden();
    expect($connection->fresh()->status)->toBe('active')->and(Transaction::count())->toBe(0)->and(OutboundPayment::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    ['get', '/accounts', 'company'], ['post', '/sync', 'company'], ['delete', '', 'company'], ['post', '/pay', 'company'], ['post', '/bulk-pay', 'company'],
    ['get', '/accounts', 'provider'], ['post', '/sync', 'provider'], ['delete', '', 'provider'], ['post', '/pay', 'provider'], ['post', '/bulk-pay', 'provider'],
]);

it('denies stale membership and tenantless operations without provider calls', function (): void {
    $other = revolutCoverageUser();
    $connection = revolutCoverageConnection($other);
    $team = $other->currentTeam;
    $team->users()->attach($this->revolutOwner->id, ['role' => 'admin']);
    $this->revolutOwner->switchTeam($team);
    $this->revolutOwner->load('currentTeam', 'teams');
    DB::table('team_user')->where('team_id', $team->id)->where('user_id', $this->revolutOwner->id)->delete();
    $this->getJson('/api/revolut/authorize')->assertForbidden();
    $this->getJson('/api/revolut/connections')->assertForbidden();
    $this->postJson('/api/revolut/callback', ['code' => 'code', 'state' => 'state'])->assertForbidden();
    $this->getJson('/api/revolut/connections/'.$connection->id.'/accounts')->assertForbidden();
    $this->revolutOwner->forceFill(['current_team_id' => null])->save();
    $this->getJson('/api/revolut/connections')->assertForbidden();
    Http::assertNothingSent();
});

it('rejects inactive connections before import or financial instructions', function (string $suffix): void {
    $connection = revolutCoverageConnection($this->revolutOwner, ['status' => 'disconnected']);
    $payload = $suffix === '/bulk-pay' ? ['idempotency_key' => 'bulk', 'title' => 'Batch', 'payments' => [revolutCoveragePayment()]] : revolutCoveragePayment();
    $uri = '/api/revolut/connections/'.$connection->id.$suffix;
    $response = $suffix === '/accounts' ? $this->getJson($uri) : $this->postJson($uri, $payload);
    $response->assertStatus(400);
    expect(OutboundPayment::count())->toBe(0)->and(Transaction::count())->toBe(0);
    Http::assertNothingSent();
})->with(['/accounts', '/sync', '/pay', '/bulk-pay']);

it('persists exact owned account snapshots idempotently without posting transactions', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $this->revolutService->shouldReceive('getAccounts')->twice()->andReturn([
        ['id' => 'account-one', 'name' => 'Operating', 'balance' => '500.25', 'currency' => 'GBP'],
        ['id' => 'account-two', 'name' => 'Overdraft', 'type' => 'current', 'balance' => '-10.50', 'currency' => 'GBP'],
    ]);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->getJson('/api/revolut/connections/'.$connection->id.'/accounts')->assertOk()->assertJsonCount(2, 'accounts')->assertJsonPath('accounts.0.current_balance', '500.25');
    }
    $balances = BankAccountBalance::orderBy('id')->get();
    expect($balances)->toHaveCount(2)->and($balances[0]->team_id)->toBe($connection->team_id)
        ->and($balances[1]->current_balance)->toBe('-10.50')->and(Transaction::count())->toBe(0);
});

it('rolls account snapshots back on malformed later rows or provider errors', function (string $failure): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    if ($failure === 'provider') {
        $this->revolutService->shouldReceive('getAccounts')->once()->andThrow(new RuntimeException('private-provider-secret'));
    } else {
        $rows = $failure === 'shape' ? ['error' => 'private-provider-secret'] : [
            ['id' => 'good', 'name' => 'Operating', 'balance' => '500.25', 'currency' => 'GBP'],
            ['id' => 'bad', 'name' => 'Invalid precision', 'balance' => '1.005', 'currency' => 'GBP'],
        ];
        $this->revolutService->shouldReceive('getAccounts')->once()->andReturn($rows);
    }
    $response = $this->getJson('/api/revolut/connections/'.$connection->id.'/accounts')->assertStatus(500)->assertJsonPath('message', 'Failed to get accounts');
    expect($response->getContent())->not->toContain('private-provider-secret');
    expect(BankAccountBalance::count())->toBe(0);
})->with(['provider', 'precision', 'shape']);

it('imports exact signed money and currency with repeat-safe financial identity', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner, ['last_synced_at' => '2026-10-01 00:00:00']);
    $row = revolutCoverageRow();
    $this->revolutService->shouldReceive('getTransactions')->twice()->with(Mockery::on(fn ($actual): bool => $actual->id === $connection->id), Mockery::on(fn ($date): bool => str_starts_with($date, '2026-10-01')))->andReturn([$row]);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->postJson('/api/revolut/connections/'.$connection->id.'/sync')->assertOk()->assertJsonPath('summary.total_processed', 1);
    }
    $transaction = Transaction::sole();
    expect($transaction->team_id)->toBe($connection->team_id)->and($transaction->amount)->toBe('25.50')
        ->and($transaction->type)->toBe('debit')->and($transaction->status)->toBe('posted')
        ->and($transaction->transaction_date->toDateString())->toBe('2026-11-01')
        ->and($transaction->currency->code)->toBe('GBP')->and(BankFeedTransaction::count())->toBe(1)
        ->and(BankFeedTransaction::sole()->raw_data)->toBe($row);
});

it('recognizes only explicit completed states and retains transaction classifications', function (string $state, string $expected, string $type, string $category): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $this->revolutService->shouldReceive('getTransactions')->once()->andReturn([revolutCoverageRow(['state' => $state, 'type' => $type, 'legs' => [['amount' => '0.30', 'currency' => 'GBP']]])]);
    $this->postJson('/api/revolut/connections/'.$connection->id.'/sync')->assertOk();
    $transaction = Transaction::sole();
    expect($transaction->status)->toBe($expected)->and($transaction->category)->toBe($category)
        ->and($transaction->type)->toBe('credit')->and($transaction->amount)->toBe('0.30');
})->with([
    ['pending', 'pending', 'transfer', 'transfer'], ['completed', 'posted', 'card_refund', 'refund'],
    ['declined', 'declined', 'atm', 'cash'], ['failed', 'failed', 'fee', 'fee'],
    ['reverted', 'cancelled', 'topup', 'topup'], ['unknown', 'pending', 'fx', 'exchange'],
    ['', 'pending', 'unrecognized', 'uncategorized'],
]);

it('uses merchant fallbacks without inventing recognition for absent state', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $row = revolutCoverageRow(['type' => '', 'merchant' => ['name' => 'Synthetic merchant', 'category' => 'TRAVEL']]);
    unset($row['reference'], $row['state']);
    $this->revolutService->shouldReceive('getTransactions')->once()->andReturn([$row]);
    $this->postJson('/api/revolut/connections/'.$connection->id.'/sync')->assertOk();
    expect(Transaction::sole()->category)->toBe('travel')->and(Transaction::sole()->description)->toBe('Synthetic merchant')
        ->and(Transaction::sole()->status)->toBe('pending');
});

it('rejects malformed transactions and rolls the entire import back', function (array $invalid): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $this->revolutService->shouldReceive('getTransactions')->once()->andReturn([revolutCoverageRow(), revolutCoverageRow(['id' => 'invalid-row'] + $invalid)]);
    $this->postJson('/api/revolut/connections/'.$connection->id.'/sync')->assertStatus(500)->assertJsonPath('message', 'Failed to sync transactions');
    expect(Transaction::count())->toBe(0)->and(BankFeedTransaction::count())->toBe(0)->and($connection->fresh()->last_synced_at)->toBeNull();
})->with([
    [['legs' => [['amount' => '1.005', 'currency' => 'GBP']]]],
    [['created_at' => '2026-02-30']], [['created_at' => 'not-a-date']],
    [['legs' => []]], [['legs' => [['amount' => '1.00', 'currency' => 'GBP'], ['amount' => '-1.00', 'currency' => 'GBP']]]],
    [['legs' => [['amount' => '1.00', 'currency' => 'ZZZ']]]],
]);

it('preserves reconciled money and raw evidence when a repeat feed changes it', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $row = revolutCoverageRow();
    $this->revolutService->shouldReceive('getTransactions')->times(3)->andReturn([$row], [$row], [revolutCoverageRow(['legs' => [['amount' => '-99.00', 'currency' => 'GBP']]])]);
    $uri = '/api/revolut/connections/'.$connection->id.'/sync';
    $this->postJson($uri)->assertOk();
    Transaction::sole()->update(['reconciled' => true]);
    $this->postJson($uri)->assertOk();
    $this->postJson($uri)->assertStatus(500);
    expect(Transaction::sole()->amount)->toBe('25.50')->and(Transaction::sole()->reconciled)->toBeTrue()
        ->and(BankFeedTransaction::sole()->raw_data)->toBe($row);
});

it('does not adopt another company or connection transaction identity', function (string $boundary): void {
    $foreign = revolutCoverageConnection($boundary === 'company' ? revolutCoverageUser() : $this->revolutOwner);
    $own = revolutCoverageConnection($this->revolutOwner);
    $transaction = Transaction::query()->create([
        'external_id' => 'synthetic-transaction', 'bank_connection_id' => $foreign->id, 'team_id' => $foreign->team_id,
        'transaction_date' => '2026-11-01', 'amount' => '10.00', 'type' => 'debit', 'status' => 'pending',
    ]);
    $this->revolutService->shouldReceive('getTransactions')->once()->andReturn([revolutCoverageRow()]);
    $this->postJson('/api/revolut/connections/'.$own->id.'/sync')->assertStatus(500);
    expect(Transaction::count())->toBe(1)->and($transaction->fresh()->amount)->toBe('10.00')
        ->and($transaction->fresh()->bank_connection_id)->toBe($foreign->id)->and(BankFeedTransaction::count())->toBe(0);
})->with(['company', 'connection']);

it('handles provider import failure without leaking payloads or mutating sync evidence', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $this->revolutService->shouldReceive('getTransactions')->once()->andThrow(new RuntimeException('private-provider-secret'));
    $response = $this->postJson('/api/revolut/connections/'.$connection->id.'/sync')->assertStatus(500);
    expect($response->getContent())->not->toContain('private-provider-secret');
    expect(Transaction::count())->toBe(0)->and($connection->fresh()->last_synced_at)->toBeNull();
});

it('disconnects only the owned connection and preserves financial history', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $this->deleteJson('/api/revolut/connections/'.$connection->id)->assertOk();
    expect($connection->fresh()->status)->toBe('disconnected')->and(BankConnection::count())->toBe(1);
    Http::assertNothingSent();
});

it('rejects fractional-cent payment instructions before recording an approval or sending', function (string $suffix): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $payment = revolutCoveragePayment(['amount' => '25.005']);
    $payload = $suffix === '/pay' ? $payment : ['idempotency_key' => 'batch', 'title' => 'Batch', 'payments' => [$payment]];
    $this->postJson('/api/revolut/connections/'.$connection->id.$suffix, $payload)->assertUnprocessable();
    expect(OutboundPayment::count())->toBe(0);
    Http::assertNothingSent();
})->with(['/pay', '/bulk-pay']);

it('retains pending payment approval and durable identity after cache expiry', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    Role::findOrCreate('approver', 'web');
    ApprovalRule::query()->create(['team_id' => $connection->team_id, 'approvable_type' => 'OutboundPayment', 'min_amount' => 0, 'steps' => ['approver'], 'is_active' => true]);
    $this->postJson('/api/revolut/connections/'.$connection->id.'/pay', revolutCoveragePayment())->assertStatus(202)->assertJsonPath('status', 'pending_approval');
    Cache::forget('revolut_pay:'.$connection->id.':synthetic-payment-key');
    $this->postJson('/api/revolut/connections/'.$connection->id.'/pay', revolutCoveragePayment(['amount' => '30.00']))->assertStatus(409);
    expect(OutboundPayment::count())->toBe(1)->and(OutboundPayment::sole()->amount)->toBe('25.50');
    Http::assertNothingSent();
});

it('records a failed provider send once and requires review before ambiguous replay', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    Log::spy();
    $this->revolutService->shouldReceive('sendPayment')->once()->andThrow(new RuntimeException('private-provider-secret'));
    $this->postJson('/api/revolut/connections/'.$connection->id.'/pay', revolutCoveragePayment())->assertStatus(500)->assertJsonPath('message', 'Payment failed to send');
    $this->postJson('/api/revolut/connections/'.$connection->id.'/pay', revolutCoveragePayment())->assertStatus(409);
    expect(OutboundPayment::count())->toBe(1)->and(OutboundPayment::sole()->status)->toBe('failed');
    $paymentId = OutboundPayment::sole()->id;
    Log::shouldHaveReceived('error', ['Outbound payment send failed', ['payment_id' => $paymentId, 'exception_class' => RuntimeException::class]])->once();
    Log::shouldHaveReceived('error', ['Revolut payment failed to send', ['payment_id' => $paymentId]])->once();
    Log::shouldNotHaveReceived('error', [Mockery::on(fn ($message): bool => str_contains((string) $message, 'private-provider-secret')), Mockery::any()]);
    Log::shouldNotHaveReceived('error', [Mockery::any(), Mockery::on(fn ($context): bool => str_contains((string) json_encode($context), 'private-provider-secret'))]);
});

it('submits a synthetic bulk draft once with its schedule and rejects duplicate submission', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $payments = [revolutCoveragePayment()];
    $this->revolutService->shouldReceive('sendBulkPayment')->once()->with(Mockery::type(BankConnection::class), 'Synthetic batch', $payments, '2026-12-01')->andReturn(['id' => 'synthetic-draft']);
    $payload = ['idempotency_key' => 'bulk-key', 'title' => 'Synthetic batch', 'schedule_for' => '2026-12-01', 'payments' => $payments];
    $uri = '/api/revolut/connections/'.$connection->id.'/bulk-pay';
    $this->postJson($uri, $payload)->assertCreated()->assertJsonPath('payment_draft.id', 'synthetic-draft');
    $this->postJson($uri, $payload)->assertStatus(409);
    expect(OutboundPayment::count())->toBe(0)->and(Transaction::count())->toBe(0);
});

it('redacts bulk draft provider failure and releases the failed cache claim', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $this->revolutService->shouldReceive('sendBulkPayment')->once()->andThrow(new RuntimeException('private-provider-secret'));
    $response = $this->postJson('/api/revolut/connections/'.$connection->id.'/bulk-pay', ['idempotency_key' => 'bulk-key', 'title' => 'Batch', 'payments' => [revolutCoveragePayment()]]);
    $response->assertStatus(500)->assertJsonPath('message', 'Failed to send bulk payment');
    expect($response->getContent())->not->toContain('private-provider-secret');
    expect(Cache::has('revolut_bulkpay:'.$connection->id.':bulk-key'))->toBeFalse();
});

it('rejects unsigned webhooks and handles verified notifications without financial mutations', function (string $event): void {
    $this->app->instance(RevolutService::class, new RevolutService());
    $payload = ['event' => $event, 'data' => ['id' => 'synthetic-provider-event', 'state' => 'completed', 'amount' => '9999.00']];
    $raw = json_encode($payload);
    $this->call('POST', '/api/webhooks/revolut', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertUnauthorized();
    $signature = 'v1='.hash_hmac('sha256', $raw, 'synthetic-signing-secret');
    $this->call('POST', '/api/webhooks/revolut', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_REVOLUT_SIGNATURE' => $signature], $raw)->assertOk()->assertJsonPath('success', true);
    expect(Transaction::count())->toBe(0)->and(OutboundPayment::count())->toBe(0);
})->with(['TransactionCreated', 'TransactionStateChanged', 'UnknownSyntheticEvent']);

it('keeps webhook processing errors retryable and redacts secrets', function (): void {
    $this->revolutService->shouldReceive('verifyWebhookSignature')->once()->andThrow(new RuntimeException('private-signature-secret'));
    $response = $this->postJson('/api/webhooks/revolut', ['event' => 'TransactionCreated'])->assertStatus(503)->assertJsonPath('success', false);
    expect($response->getContent())->not->toContain('private-signature-secret');
    expect(Transaction::count())->toBe(0);
});

it('keeps connection listing failures private without returning another company data', function (): void {
    revolutCoverageConnection($this->revolutOwner);
    DB::connection()->beforeExecuting(function (string $query): void {
        if (str_contains($query, 'bank_connections') && str_starts_with(strtolower($query), 'select')) {
            throw new RuntimeException('private-database-detail');
        }
    });
    $response = $this->getJson('/api/revolut/connections')->assertStatus(500)->assertJsonPath('message', 'Failed to retrieve connections');
    expect($response->getContent())->not->toContain('private-database-detail');
});

it('rolls a failed disconnect back instead of reporting success', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    BankConnection::updating(function (BankConnection $candidate): void {
        if ($candidate->status === 'disconnected') {
            throw new RuntimeException('private-storage-error');
        }
    });
    $response = $this->deleteJson('/api/revolut/connections/'.$connection->id)->assertStatus(500)->assertJsonPath('message', 'Failed to remove connection');
    expect($response->getContent())->not->toContain('private-storage-error');
    expect($connection->fresh()->status)->toBe('active');
});

it('releases the cache claim after a payment instruction could not be persisted', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    OutboundPayment::creating(fn () => throw new RuntimeException('private-storage-error'));
    $response = $this->postJson('/api/revolut/connections/'.$connection->id.'/pay', revolutCoveragePayment())->assertStatus(500)->assertJsonPath('message', 'Failed to send payment');
    expect($response->getContent())->not->toContain('private-storage-error');
    expect(OutboundPayment::count())->toBe(0)->and(Cache::has('revolut_pay:'.$connection->id.':synthetic-payment-key'))->toBeFalse();
    Http::assertNothingSent();
});

it('rolls back malformed transaction collection envelopes', function (array $rows): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $this->revolutService->shouldReceive('getTransactions')->once()->andReturn($rows);
    $this->postJson('/api/revolut/connections/'.$connection->id.'/sync')->assertStatus(500);
    expect(Transaction::count())->toBe(0)->and(BankFeedTransaction::count())->toBe(0);
})->with([[['error' => 'invalid collection']], [['invalid-row']]]);

it('uses a verified SDK account response and redacts provider failure logs', function (bool $failure): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $service = new RevolutService();
    Http::fake(['sandbox-b2b.revolut.com/api/1.0/accounts/synthetic-account' => Http::response($failure ? ['private-provider-secret' => 'rejected'] : ['id' => 'synthetic-account', 'balance' => '10.25'], $failure ? 403 : 200)]);
    Log::spy();
    if ($failure) {
        expect(fn () => $service->getAccount($connection, 'synthetic-account'))->toThrow(Exception::class, 'Failed to get account');
        Log::shouldHaveReceived('error')->once()->with('Revolut get account failed', ['connection_id' => $connection->id, 'account_id' => 'synthetic-account', 'exception_class' => Exception::class]);
    } else {
        expect($service->getAccount($connection, 'synthetic-account'))->toBe(['id' => 'synthetic-account', 'balance' => '10.25']);
    }
    Http::assertSentCount(1);
    expect(Transaction::count())->toBe(0);
})->with([true, false]);

it('does not advance the SDK sync timestamp after a denied provider response', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $service = new RevolutService();
    Http::fake(['sandbox-b2b.revolut.com/api/1.0/transactions*' => Http::response(['error' => 'private-provider-secret'], 403)]);
    Log::spy();
    expect(fn () => $service->getTransactions($connection))->toThrow(Exception::class);
    expect($connection->fresh()->last_synced_at)->toBeNull();
    Log::shouldHaveReceived('error')->once()->with('Revolut get transactions failed', Mockery::on(fn (array $context): bool => $context['connection_id'] === $connection->id
        && is_a($context['exception_class'], Exception::class, true)
        && ! str_contains(json_encode($context), 'private-provider-secret')));
    Http::assertSentCount(1);
});

it('retries a transient provider read once and commits the financial import only after success', function (): void {
    $connection = revolutCoverageConnection($this->revolutOwner);
    $this->app->instance(RevolutService::class, new RevolutService());
    Http::fake(['sandbox-b2b.revolut.com/api/1.0/transactions*' => Http::sequence()
        ->push(['error' => 'synthetic-transient'], 503)
        ->push([revolutCoverageRow()], 200)]);
    $this->postJson('/api/revolut/connections/'.$connection->id.'/sync')->assertOk()->assertJsonPath('summary.total_processed', 1);
    Http::assertSentCount(2);
    expect(Transaction::count())->toBe(1)->and(Transaction::sole()->amount)->toBe('25.50')
        ->and(Transaction::sole()->team_id)->toBe($connection->team_id)
        ->and(BankFeedTransaction::count())->toBe(1)->and($connection->fresh()->last_synced_at)->not->toBeNull();
});
