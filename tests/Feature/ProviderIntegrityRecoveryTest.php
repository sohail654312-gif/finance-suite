<?php

declare(strict_types=1);

use App\Jobs\SyncAccountingProviderConnectionJob;
use App\Models\Account;
use App\Models\QboConnection;
use App\Models\SageConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Models\XeroConnection;
use App\Services\ProviderTransactionImporter;
use App\Services\QuickBooksService;
use App\Services\SageService;
use App\Services\TeamManagementService;
use App\Services\XeroService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($this->owner);
    $this->teamId = (int) $this->owner->fresh()->current_team_id;
    $this->connectionAttributes = ['user_id' => $this->owner->id, 'team_id' => $this->teamId, 'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'token_expires_at' => now()->addHour(), 'status' => 'active'];
    $this->row = ['external_id' => 'xero:bank:synthetic-1', 'date' => '2026-01-15', 'amount' => '-25.50', 'description' => 'Synthetic supplies', 'type' => 'spend'];
});

it('imports provider transactions idempotently without posting or reconciling them', function (): void {
    $importer = app(ProviderTransactionImporter::class);
    expect($importer->handle([$this->row], $this->teamId, fn (array $row): array => $row))->toBe(1);
    expect($importer->handle([$this->row], $this->teamId, fn (array $row): array => $row))->toBe(1);
    expect(Transaction::count())->toBe(1);
    expect(Transaction::first()->amount)->toBe('-25.50');
    expect(Transaction::first()->reconciled)->toBeFalse();
    expect(Transaction::first()->status)->toBe('pending');
});

it('rejects provider imports with invalid identity date or decimal precision atomically', function (array $invalid): void {
    expect(fn () => app(ProviderTransactionImporter::class)->handle([$this->row, array_replace($this->row, $invalid)], $this->teamId, fn (array $row): array => $row))->toThrow(InvalidArgumentException::class);
    expect(Transaction::count())->toBe(0);
})->with([
    'missing identity' => [['external_id' => '']],
    'overlong identity' => [['external_id' => str_repeat('x', 256)]],
    'invalid date' => [['date' => '2026-02-30']],
    'unparseable date' => [['date' => 'invalid']],
    'fractional cent' => [['amount' => '25.501']],
    'out of schema range' => [['amount' => '10000000000.00']],
]);

it('requires a company for provider imports', function (?int $teamId): void {
    expect(fn () => app(ProviderTransactionImporter::class)->handle([], $teamId, fn (array $row): array => $row))->toThrow(LogicException::class);
})->with([null, 0]);

it('rejects cross-company provider transaction identities and accounts', function (): void {
    $other = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($other);
    $otherTeamId = (int) $other->fresh()->current_team_id;
    $importer = app(ProviderTransactionImporter::class);
    $importer->handle([$this->row], $this->teamId, fn (array $row): array => $row);
    expect(fn () => $importer->handle([$this->row], $otherTeamId, fn (array $row): array => $row))->toThrow(LogicException::class);
    $account = Account::factory()->create(['team_id' => $otherTeamId, 'xero_id' => 'bank-1']);
    expect(fn () => $importer->handle([array_replace($this->row, ['external_id' => 'xero:bank:synthetic-2', 'account_id' => $account->id])], $this->teamId, fn (array $row): array => $row))->toThrow(LogicException::class);
    expect($importer->accountId('xero_id', 'bank-1', $this->teamId))->toBeNull();
    expect($importer->accountId('xero_id', 'bank-1', $otherTeamId))->toBe($account->id);
    expect(Transaction::first()->team_id)->toBe($this->teamId);
});

it('requires review before changing reconciled provider transactions', function (): void {
    $account = Account::factory()->create(['team_id' => $this->teamId]);
    $row = $this->row + ['account_id' => $account->id];
    $importer = app(ProviderTransactionImporter::class);
    $importer->handle([$row], $this->teamId, fn (array $row): array => $row);
    Transaction::first()->update(['reconciled' => true]);
    expect($importer->handle([$row], $this->teamId, fn (array $row): array => $row))->toBe(1);
    expect(fn () => $importer->handle([array_replace($row, ['amount' => '-30.00'])], $this->teamId, fn (array $row): array => $row))->toThrow(LogicException::class);
    expect(Transaction::first()->amount)->toBe('-25.50');
});

it('handles empty provider responses and unmapped accounts safely', function (): void {
    $importer = app(ProviderTransactionImporter::class);
    expect($importer->handle([], $this->teamId, fn (array $row): array => $row))->toBe(0);
    expect($importer->accountId('qbo_id', null, $this->teamId))->toBeNull();
    expect($importer->accountId('sage_id', '', $this->teamId))->toBeNull();
    expect($importer->accountId('qbo_id', 'unknown', $this->teamId))->toBeNull();
    expect(fn () => $importer->accountId('unsafe_column', '1', $this->teamId))->toThrow(InvalidArgumentException::class);
    expect(fn () => $importer->accountId('qbo_id', '1', null))->toThrow(InvalidArgumentException::class);
});

it('runs each supported queued adapter against its owned connection', function (string $provider, string $model, string $service, array $identity): void {
    $connection = $model::create($this->connectionAttributes + $identity);
    $this->mock($service)->shouldReceive('sync')->once()->withArgs(fn ($actual): bool => $actual->is($connection))->andReturn([]);
    $job = new SyncAccountingProviderConnectionJob($provider, $connection->id);
    expect($job->uniqueId())->toBe($provider.':'.$connection->id);
    expect($job->middleware()[0]->key)->toBe($job->uniqueId());
    expect($job->afterCommit)->toBeTrue();
    $job->handle();
})->with([
    'qbo' => ['qbo', QboConnection::class, QuickBooksService::class, ['realm_id' => 'synthetic-realm']],
    'xero' => ['xero', XeroConnection::class, XeroService::class, ['tenant_id' => 'synthetic-tenant']],
    'sage' => ['sage', SageConnection::class, SageService::class, ['business_id' => 'synthetic-business']],
]);

it('rejects malformed jobs and skips deleted or inactive connections', function (): void {
    expect(fn () => new SyncAccountingProviderConnectionJob('unknown', 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => new SyncAccountingProviderConnectionJob('qbo', 0))->toThrow(InvalidArgumentException::class);
    Http::preventStrayRequests();
    (new SyncAccountingProviderConnectionJob('qbo', 999999))->handle();
    $connection = QboConnection::create(array_replace($this->connectionAttributes, ['status' => 'inactive']) + ['realm_id' => 'synthetic-realm']);
    (new SyncAccountingProviderConnectionJob('qbo', $connection->id))->handle();
    $job = new SyncAccountingProviderConnectionJob('qbo', 1);
    $job->provider = 'unsupported';
    expect(fn () => $job->handle())->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('blocks demo sync and unowned provider connections before any external request', function (): void {
    Http::preventStrayRequests();
    $connection = QboConnection::create(array_replace($this->connectionAttributes, ['team_id' => null]) + ['realm_id' => 'synthetic-realm']);
    $job = new SyncAccountingProviderConnectionJob('qbo', $connection->id);
    expect(fn () => $job->handle())->toThrow(LogicException::class);
    config()->set('finance-suite.demo', true);
    expect(fn () => $job->handle())->toThrow(LogicException::class);
    Http::assertNothingSent();
});

it('logs failed job identity without credentials or provider payloads', function (): void {
    Log::spy();
    $job = new SyncAccountingProviderConnectionJob('qbo', 123);
    $job->failed(new RuntimeException('secret-token must not be logged'));
    $job->failed(null);
    Log::shouldHaveReceived('error')->with('Accounting provider synchronization failed', ['provider' => 'qbo', 'connection_id' => 123, 'exception' => RuntimeException::class])->once();
    Log::shouldHaveReceived('error')->with('Accounting provider synchronization failed', ['provider' => 'qbo', 'connection_id' => 123, 'exception' => null])->once();
});

it('queues only active matching realms after verifying QBO signatures', function (): void {
    Bus::fake();
    config()->set('services.qbo.webhook_verifier_token', 'synthetic-verifier');
    $connection = QboConnection::create($this->connectionAttributes + ['realm_id' => 'synthetic-realm']);
    $payload = json_encode(['eventNotifications' => [['realmId' => 'synthetic-realm'], ['realmId' => 'synthetic-realm'], ['realmId' => 'unknown'], ['realmId' => null]]], JSON_THROW_ON_ERROR);
    $signature = base64_encode(hash_hmac('sha256', $payload, 'synthetic-verifier', true));
    $this->call('POST', '/api/webhooks/qbo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_INTUIT_SIGNATURE' => $signature], $payload)->assertOk()->assertJsonPath('connections_queued', 1);
    Bus::assertDispatched(SyncAccountingProviderConnectionJob::class, fn ($job): bool => $job->provider === 'qbo' && $job->connectionId === $connection->id);
    Bus::assertDispatchedTimes(SyncAccountingProviderConnectionJob::class, 1);
    $this->postJson('/api/webhooks/qbo', [], ['intuit-signature' => 'invalid'])->assertUnauthorized();
});
