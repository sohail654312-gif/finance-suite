<?php

use App\Models\BankAccountBalance;
use App\Models\BankConnection;
use App\Models\BankFeedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TeamManagementService;
use App\Services\WiseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->wiseOwner = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($this->wiseOwner);
    $this->wiseOwner = $this->wiseOwner->fresh();
    Sanctum::actingAs($this->wiseOwner);
    $this->wiseService = $this->mock(WiseService::class);
    $this->wiseTransfer = ['id' => 701, 'sourceValue' => '25.50', 'created' => '2026-10-07 10:47:49', 'status' => 'outgoing_payment_sent', 'details' => ['reference' => 'Synthetic supplier payment']];
});

function wiseIntegrityConnection(User $user, array $overrides = []): BankConnection
{
    return BankConnection::create(array_replace([
        'user_id' => $user->id,
        'team_id' => $user->current_team_id,
        'bank_id' => 'wise',
        'institution_name' => 'Wise',
        'status' => 'active',
        'wise_access_token' => 'synthetic-access',
    ], $overrides));
}

it('binds a single-use Wise OAuth state to the authenticated user and encrypts tokens', function (): void {
    $this->wiseService->shouldReceive('getAuthorizationUrl')->once()->with(Mockery::on(fn ($state): bool => strlen($state) === 40))->andReturn('https://sandbox.example.test/authorize');
    $this->getJson('/api/wise/authorize')->assertOk()->assertJsonPath('authorization_url', 'https://sandbox.example.test/authorize');
    $state = Cache::get('wise_oauth_state:'.$this->wiseOwner->id);
    $this->wiseService->shouldReceive('exchangeAuthorizationCode')->once()->with('synthetic-code')->andReturn(['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_in' => 3600]);
    $response = $this->postJson('/api/wise/callback', ['code' => 'synthetic-code', 'state' => $state]);
    $response->assertCreated()->assertJsonPath('connection.status', 'active');
    expect($response->getContent())->not->toContain('synthetic-access', 'synthetic-refresh');
    $connection = BankConnection::sole();
    expect($connection->team_id)->toBe($this->wiseOwner->current_team_id)
        ->and($connection->wise_access_token)->toBe('synthetic-access')
        ->and($connection->getRawOriginal('wise_access_token'))->not->toBe('synthetic-access')
        ->and($connection->wise_token_expires_at->isFuture())->toBeTrue();
    $this->postJson('/api/wise/callback', ['code' => 'synthetic-code', 'state' => $state])->assertUnprocessable();
    expect(BankConnection::count())->toBe(1);
});

it('rejects missing codes, malformed OAuth inputs and another users state before token exchange', function (): void {
    $this->wiseService->shouldNotReceive('exchangeAuthorizationCode');
    $this->postJson('/api/wise/callback', [])->assertStatus(400);
    $this->postJson('/api/wise/callback', ['code' => ['invalid'], 'state' => 'state'])->assertUnprocessable();
    $this->postJson('/api/wise/callback', ['code' => 'code', 'state' => ['invalid']])->assertUnprocessable();
    Cache::put('wise_oauth_state:'.$this->wiseOwner->id, 'owned-state');
    $other = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($other);
    Sanctum::actingAs($other->fresh());
    $this->postJson('/api/wise/callback', ['code' => 'code', 'state' => 'owned-state'])->assertUnprocessable();
    expect(Cache::get('wise_oauth_state:'.$this->wiseOwner->id))->toBe('owned-state')->and(BankConnection::count())->toBe(0);
});

it('rolls a failed Wise token exchange back without exposing provider secrets', function (): void {
    Cache::put('wise_oauth_state:'.$this->wiseOwner->id, 'state');
    $this->wiseService->shouldReceive('exchangeAuthorizationCode')->once()->andThrow(new RuntimeException('private-provider-token'));
    Log::spy();
    $response = $this->postJson('/api/wise/callback', ['code' => 'code', 'state' => 'state']);
    $response->assertStatus(500)->assertJsonPath('message', 'Failed to connect Wise account');
    expect($response->getContent())->not->toContain('private-provider-token');
    expect(BankConnection::count())->toBe(0);
    Log::shouldHaveReceived('error')->once()->with('Failed to store Wise connection', ['user_id' => $this->wiseOwner->id, 'exception_class' => RuntimeException::class]);
});

it('lists only owned Wise connections and omits every provider credential from serialization', function (): void {
    $connection = wiseIntegrityConnection($this->wiseOwner, ['wise_refresh_token' => 'synthetic-refresh', 'revolut_access_token' => 'synthetic-revolut', 'revolut_refresh_token' => 'synthetic-revolut-refresh', 'plaid_access_token' => 'synthetic-plaid', 'credentials' => ['secret' => 'synthetic-secret']]);
    wiseIntegrityConnection($this->wiseOwner, ['bank_id' => 'revolut']);
    $other = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($other);
    wiseIntegrityConnection($other->fresh());
    $this->getJson('/api/wise/connections')->assertOk()->assertJsonCount(1, 'connections')->assertJsonPath('connections.0.id', $connection->id);
    foreach (['credentials', 'plaid_access_token', 'wise_access_token', 'wise_refresh_token', 'revolut_access_token', 'revolut_refresh_token'] as $secret) {
        expect($connection->toArray())->not->toHaveKey($secret);
    }
    expect($connection->wise_access_token)->toBe('synthetic-access');
});

it('denies Wise access to foreign company and wrong-provider connections', function (string $verb, string $suffix, string $boundary): void {
    $user = $this->wiseOwner;
    if ($boundary === 'company') {
        $user = User::factory()->create();
        app(TeamManagementService::class)->createPersonalTeamForUser($user);
        $user = $user->fresh();
    }
    $connection = wiseIntegrityConnection($user, $boundary === 'provider' ? ['bank_id' => 'revolut'] : []);
    $this->wiseService->shouldNotReceive('getProfiles');
    $this->{$verb.'Json'}('/api/wise/connections/'.$connection->id.$suffix)->assertForbidden();
    expect($connection->fresh()->status)->toBe('active')->and(Transaction::count())->toBe(0)->and(BankAccountBalance::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    ['get', '/accounts', 'company'], ['post', '/sync', 'company'], ['delete', '', 'company'],
    ['get', '/accounts', 'provider'], ['post', '/sync', 'provider'], ['delete', '', 'provider'],
]);

it('rejects inactive Wise connections before calling the provider', function (string $verb, string $suffix): void {
    $connection = wiseIntegrityConnection($this->wiseOwner, ['status' => 'disconnected']);
    $this->wiseService->shouldNotReceive('getProfiles');
    $this->{$verb.'Json'}('/api/wise/connections/'.$connection->id.$suffix)->assertStatus(400);
    Http::assertNothingSent();
})->with([['get', '/accounts'], ['post', '/sync']]);

it('refreshes owned Wise balance snapshots idempotently without creating ledger transactions', function (): void {
    $connection = wiseIntegrityConnection($this->wiseOwner);
    $this->wiseService->shouldReceive('getProfiles')->twice()->andReturn([['id' => 10, 'type' => 'business']]);
    $this->wiseService->shouldReceive('getBalances')->twice()->with(Mockery::type(BankConnection::class), 10)->andReturn([['id' => 21, 'currency' => 'GBP', 'amount' => ['value' => '1250.25']], ['currency' => 'EUR', 'amount' => ['value' => '40.00']]]);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->getJson('/api/wise/connections/'.$connection->id.'/accounts')->assertOk()->assertJsonCount(2, 'accounts')->assertJsonPath('accounts.0.current_balance', '1250.25');
    }
    expect(BankAccountBalance::count())->toBe(2)->and(Transaction::count())->toBe(0);
    $balance = BankAccountBalance::where('plaid_account_id', 'wise_10_21')->sole();
    expect($balance->available_balance)->toBe('1250.25')->and($balance->bankConnection->id)->toBe($connection->id);
});

it('rolls partial Wise balance imports back when a later profile fails', function (): void {
    $connection = wiseIntegrityConnection($this->wiseOwner);
    $this->wiseService->shouldReceive('getProfiles')->once()->andReturn([['id' => 10], ['id' => 11]]);
    $this->wiseService->shouldReceive('getBalances')->with(Mockery::type(BankConnection::class), 10)->andReturn([['id' => 21, 'currency' => 'GBP', 'amount' => ['value' => '50.00']]]);
    $this->wiseService->shouldReceive('getBalances')->with(Mockery::type(BankConnection::class), 11)->andThrow(new RuntimeException('private-provider-token'));
    $response = $this->getJson('/api/wise/connections/'.$connection->id.'/accounts');
    $response->assertStatus(500)->assertJsonPath('message', 'Failed to get accounts');
    expect($response->getContent())->not->toContain('private-provider-token');
    expect(BankAccountBalance::count())->toBe(0);
});

it('maps Wise transfer lifecycle honestly and keeps repeat imports idempotent', function (string $status, string $expected): void {
    $connection = wiseIntegrityConnection($this->wiseOwner, ['last_synced_at' => '2026-10-01 00:00:00']);
    $this->wiseService->shouldReceive('getProfiles')->twice()->andReturn([['id' => 10]]);
    $this->wiseService->shouldReceive('getTransfers')->twice()->with(Mockery::type(BankConnection::class), 10, Mockery::on(fn ($date): bool => str_starts_with($date, '2026-10-01')))->andReturn([array_replace($this->wiseTransfer, ['status' => $status])]);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->postJson('/api/wise/connections/'.$connection->id.'/sync')->assertOk()->assertJsonPath('summary.total_processed', 1);
    }
    $transaction = Transaction::sole();
    expect($transaction->amount)->toBe('25.50')->and($transaction->transaction_date->toDateString())->toBe('2026-10-07')
        ->and($transaction->team_id)->toBe($this->wiseOwner->current_team_id)
        ->and($transaction->description)->toBe('Synthetic supplier payment')
        ->and($transaction->status)->toBe($expected)->and($transaction->reconciled)->toBeFalse();
    $feed = BankFeedTransaction::sole();
    expect($feed->transaction_id)->toBe($transaction->getKey())->and($feed->transaction->getKey())->toBe($transaction->getKey())->and($feed->bankConnection->id)->toBe($connection->id)->and($feed->raw_data['id'])->toBe(701);
})->with([
    ['incoming_payment_waiting', 'pending'], ['incoming_payment_initiated', 'pending'], ['processing', 'pending'],
    ['funds_converted', 'pending'], ['outgoing_payment_sent', 'posted'], ['cancelled', 'cancelled'],
    ['bounced_back', 'failed'], ['funds_refunded', 'failed'], ['charged_back', 'failed'], ['unknown', 'pending'],
]);

it('rejects invalid Wise transfer data atomically instead of inventing dates or rounding cents', function (array $invalid): void {
    $connection = wiseIntegrityConnection($this->wiseOwner);
    $this->wiseService->shouldReceive('getProfiles')->once()->andReturn([['id' => 10]]);
    $this->wiseService->shouldReceive('getTransfers')->once()->andReturn([$this->wiseTransfer, array_replace($this->wiseTransfer, ['id' => 702], $invalid)]);
    $this->postJson('/api/wise/connections/'.$connection->id.'/sync')->assertStatus(500);
    expect(Transaction::count())->toBe(0)->and(BankFeedTransaction::count())->toBe(0);
})->with([
    [['id' => '']], [['created' => null]], [['created' => '2026-02-30 10:47:49']], [['created' => 'tomorrow']],
    [['created' => '2026-10-07 24:47:49']],
    [['sourceValue' => null]], [['sourceValue' => '-25.50']], [['sourceValue' => '25.501']], [['sourceValue' => '1e2']], [['sourceValue' => '10000000000.00']],
]);

it('requires review before changing a reconciled Wise transfer and preserves its original amount', function (): void {
    $connection = wiseIntegrityConnection($this->wiseOwner);
    $this->wiseService->shouldReceive('getProfiles')->times(3)->andReturn([['id' => 10]]);
    $this->wiseService->shouldReceive('getTransfers')->times(3)->andReturn([$this->wiseTransfer], [$this->wiseTransfer], [array_replace($this->wiseTransfer, ['sourceValue' => '30.00'])]);
    $url = '/api/wise/connections/'.$connection->id.'/sync';
    $this->postJson($url)->assertOk();
    Transaction::sole()->update(['reconciled' => true]);
    $this->postJson($url)->assertOk();
    $this->postJson($url)->assertStatus(500);
    expect(Transaction::sole()->amount)->toBe('25.50')->and(Transaction::sole()->reconciled)->toBeTrue();
    expect(BankFeedTransaction::sole()->raw_data['sourceValue'])->toBe('25.50');
});

it('does not overwrite a transfer identity assigned to another bank connection', function (): void {
    $first = wiseIntegrityConnection($this->wiseOwner);
    $second = wiseIntegrityConnection($this->wiseOwner);
    $this->wiseService->shouldReceive('getProfiles')->twice()->andReturn([['id' => 10]]);
    $this->wiseService->shouldReceive('getTransfers')->twice()->andReturn([$this->wiseTransfer]);
    $this->postJson('/api/wise/connections/'.$first->id.'/sync')->assertOk();
    $this->postJson('/api/wise/connections/'.$second->id.'/sync')->assertStatus(500);
    expect(Transaction::sole()->bank_connection_id)->toBe($first->id)->and(BankFeedTransaction::count())->toBe(1);
});

it('disconnects only the owned Wise connection without mutating its imported records', function (): void {
    $connection = wiseIntegrityConnection($this->wiseOwner);
    $this->deleteJson('/api/wise/connections/'.$connection->id)->assertOk();
    expect($connection->fresh()->status)->toBe('disconnected')->and(BankConnection::count())->toBe(1);
    Http::assertNothingSent();
});

it('rejects unsigned Wise webhooks before recording provider events', function (): void {
    $this->wiseService->shouldReceive('verifyWebhookSignature')->once()->andReturnFalse();
    Log::spy();
    $this->postJson('/api/webhooks/wise', ['event_type' => 'transfers#state-change'])->assertUnauthorized();
    Log::shouldNotHaveReceived('info');
    expect(Transaction::count())->toBe(0);
});

it('rejects Wise webhooks cleanly when no public key or provider credentials are configured', function (): void {
    app()->forgetInstance(WiseService::class);
    config()->set('services.wise.webhook_public_key', null);
    config()->set('services.wise.client_id', null);
    config()->set('services.wise.client_secret', null);
    $this->postJson('/api/webhooks/wise', ['event_type' => 'transfers#state-change'])->assertUnauthorized();
    expect(Transaction::count())->toBe(0);
    Http::assertNothingSent();
});

it('records signed Wise notifications without pretending to update the ledger', function (string $event, string $message): void {
    $this->wiseService->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
    Log::spy();
    $this->postJson('/api/webhooks/wise', ['event_type' => $event, 'data' => ['resource' => ['id' => 701], 'current_state' => 'processing', 'currency' => 'GBP']], ['X-Signature-SHA256' => 'synthetic-signature'])->assertOk();
    Log::shouldHaveReceived('info')->with($message, Mockery::type('array'))->once();
    expect(Transaction::count())->toBe(0)->and(BankFeedTransaction::count())->toBe(0);
})->with([
    ['transfers#state-change', 'Wise transfer state changed'], ['transfers#active-cases', 'Wise transfer has active case'],
    ['balances#credit', 'Wise balance credited'], ['balances#update', 'Wise balance updated'], ['future-event', 'Unhandled Wise webhook event'],
]);
