<?php

declare(strict_types=1);

use App\Models\BankConnection;
use App\Models\BankFeedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BankFeedService;
use App\Services\TeamManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['finance-suite.demo' => false, 'services.bank_feeds' => ['url' => 'https://feed.example.test', 'key' => 'synthetic-feed-key']]);
    $this->feedOwner = User::factory()->create();
    $this->feedTeam = app(TeamManagementService::class)->createPersonalTeamForUser($this->feedOwner);
    $this->actingAs($this->feedOwner->fresh());
    $this->feedConnection = app(BankFeedService::class)->connectBank(['access' => 'synthetic-bank-key'], 'synthetic-bank');
    $this->feedRow = ['id' => 'synthetic-feed-1', 'date' => '2026-10-07', 'amount' => '-25.50', 'description' => 'Synthetic payroll'];
});

it('stores bank credentials once as an encrypted array and hides them from serialization', function (): void {
    $connection = $this->feedConnection->fresh();
    expect($connection->credentials)->toBe(['access' => 'synthetic-bank-key'])
        ->and($connection->getRawOriginal('credentials'))->not->toContain('synthetic-bank-key')
        ->and($connection->toArray())->not->toHaveKey('credentials')
        ->and($connection->team_id)->toBe($this->feedTeam->id)
        ->and($connection->user_id)->toBe($this->feedOwner->id);
});

it('requires a bank identity and credentials before creating a connection', function (string $bankId, array $credentials): void {
    expect(fn () => app(BankFeedService::class)->connectBank($credentials, $bankId))->toThrow(InvalidArgumentException::class);
    expect(BankConnection::count())->toBe(1);
})->with(['empty bank' => ['', ['access' => 'synthetic']], 'overlong bank' => [str_repeat('b', 256), ['access' => 'synthetic']], 'missing credentials' => ['bank', []]]);

it('requires the selected company owner and rejects foreign connections before any request', function (string $boundary): void {
    if ($boundary === 'guest') {
        Auth::logout();
    } else {
        $other = User::factory()->create();
        app(TeamManagementService::class)->createPersonalTeamForUser($other);
        if ($boundary === 'member') {
            $this->feedTeam->users()->attach($other->id, ['role' => 'editor']);
            $other->forceFill(['current_team_id' => $this->feedTeam->id])->save();
        }
        $this->actingAs($other->fresh());
    }
    expect(fn () => app(BankFeedService::class)->importTransactions($this->feedConnection))->toThrow(AuthorizationException::class);
    if ($boundary !== 'foreign owner') {
        expect(fn () => app(BankFeedService::class)->connectBank(['access' => 'synthetic'], 'bank'))->toThrow(AuthorizationException::class);
    }
    Http::assertNothingSent();
})->with(['guest', 'member', 'foreign owner']);

it('fails safely when feed configuration is missing or insecure', function (mixed $settings): void {
    config(['services.bank_feeds' => $settings]);
    expect(fn () => app(BankFeedService::class)->importTransactions($this->feedConnection))->toThrow(LogicException::class, 'not configured');
    Http::assertNothingSent();
})->with(['absent' => [null], 'no key' => [['url' => 'https://feed.example.test']], 'no URL' => [['key' => 'synthetic']], 'insecure URL' => [['url' => 'http://feed.example.test', 'key' => 'synthetic']]]);

it('blocks live feed operations in demo mode and inactive connections', function (): void {
    $this->feedConnection->update(['status' => 'inactive']);
    expect(fn () => app(BankFeedService::class)->importTransactions($this->feedConnection))->toThrow(LogicException::class, 'active');
    config(['finance-suite.demo' => true]);
    expect(fn () => app(BankFeedService::class)->importTransactions($this->feedConnection))->toThrow(LogicException::class, 'demo mode')
        ->and(fn () => app(BankFeedService::class)->connectBank(['access' => 'synthetic'], 'bank'))->toThrow(LogicException::class, 'demo mode');
    Http::assertNothingSent();
});

it('imports an exact signed amount idempotently without posting or reconciling the feed', function (): void {
    Http::fake(['feed.example.test/*' => Http::response(['transactions' => [$this->feedRow]])]);
    $service = app(BankFeedService::class);
    $service->importTransactions($this->feedConnection);
    $service->importTransactions($this->feedConnection);
    $transaction = Transaction::sole();
    $feed = BankFeedTransaction::sole();
    expect($transaction->amount)->toBe('-25.50')
        ->and($transaction->team_id)->toBe($this->feedTeam->id)
        ->and($transaction->bank_connection_id)->toBe($this->feedConnection->id)
        ->and($transaction->status)->toBe('pending')
        ->and($transaction->reconciled)->toBeFalse()
        ->and($feed->raw_data)->toBe($this->feedRow)
        ->and($feed->transaction->getKey())->toBe($transaction->getKey())
        ->and($this->feedConnection->fresh()->last_synced_at)->not->toBeNull();
    Http::assertSent(fn ($request): bool => $request->method() === 'GET' && $request->hasHeader('Authorization', 'synthetic-feed-key') && $request['bank_id'] === 'synthetic-bank' && $request['credentials'] === ['access' => 'synthetic-bank-key']);
});

it('retains reconciled feed records on repeat and rejects a changed amount atomically', function (): void {
    Http::fake(['feed.example.test/*' => Http::response(['transactions' => [$this->feedRow]])]);
    $service = app(BankFeedService::class);
    $service->importTransactions($this->feedConnection);
    Transaction::sole()->update(['reconciled' => true]);
    $service->importTransactions($this->feedConnection);
    Http::fake(['feed.example.test/*' => Http::response(['transactions' => [array_replace($this->feedRow, ['amount' => '-26.00'])]])]);
    expect(fn () => $service->importTransactions($this->feedConnection))->toThrow(LogicException::class, 'require review');
    expect(Transaction::sole()->reconciled)->toBeTrue()->and(Transaction::sole()->amount)->toBe('-25.50')
        ->and(BankFeedTransaction::sole()->raw_data['amount'])->toBe('-25.50');
});

it('rejects malformed transfers and rolls back earlier rows and the sync timestamp', function (array $invalid): void {
    Http::fake(['feed.example.test/*' => Http::response(['transactions' => [$this->feedRow, array_replace($this->feedRow, ['id' => 'synthetic-invalid'], $invalid)]])]);
    expect(fn () => app(BankFeedService::class)->importTransactions($this->feedConnection))->toThrow(InvalidArgumentException::class);
    expect(Transaction::count())->toBe(0)->and(BankFeedTransaction::count())->toBe(0)
        ->and($this->feedConnection->fresh()->last_synced_at)->toBeNull();
})->with([
    'missing identity' => [['id' => null]], 'empty identity' => [['id' => '']], 'array identity' => [['id' => []]], 'long identity' => [['id' => str_repeat('x', 256)]],
    'missing date' => [['date' => null]], 'invalid date' => [['date' => '2026-02-30']], 'timestamp' => [['date' => '2026-10-07 00:00:00']],
    'missing amount' => [['amount' => null]], 'fractional cent' => [['amount' => '1.001']], 'scientific amount' => [['amount' => '1e3']], 'out of range' => [['amount' => '10000000000.00']],
    'missing description' => [['description' => null]],
]);

it('rejects malformed response shapes and accepts an empty successful response', function (mixed $rows): void {
    Http::fake(['feed.example.test/*' => Http::response(['transactions' => $rows])]);
    expect(fn () => app(BankFeedService::class)->importTransactions($this->feedConnection))->toThrow(InvalidArgumentException::class);
    expect(Transaction::count())->toBe(0)->and($this->feedConnection->fresh()->last_synced_at)->toBeNull();
})->with(['absent list' => [null], 'object instead of list' => [['id' => 'wrong']], 'non-object row' => [['wrong']]]);

it('records a successful empty import without inventing transactions', function (): void {
    Http::fake(['feed.example.test/*' => Http::response(['transactions' => []])]);
    app(BankFeedService::class)->importTransactions($this->feedConnection);
    expect(Transaction::count())->toBe(0)->and($this->feedConnection->fresh()->last_synced_at)->not->toBeNull();
});

it('rejects identity reuse across companies and bank connections', function (bool $foreignCompany): void {
    $otherConnection = BankConnection::create(['bank_id' => 'other-bank', 'status' => 'active', 'team_id' => $this->feedTeam->id]);
    $teamId = $this->feedTeam->id;
    if ($foreignCompany) {
        $other = User::factory()->create();
        $teamId = app(TeamManagementService::class)->createPersonalTeamForUser($other)->id;
        $otherConnection->update(['team_id' => $teamId]);
    }
    Transaction::factory()->create(['external_id' => $this->feedRow['id'], 'team_id' => $teamId, 'bank_connection_id' => $otherConnection->id, 'amount' => 99]);
    Http::fake(['feed.example.test/*' => Http::response(['transactions' => [$this->feedRow]])]);
    expect(fn () => app(BankFeedService::class)->importTransactions($this->feedConnection))->toThrow(LogicException::class, 'another company or connection');
    expect(Transaction::sole()->amount)->toBe('99.00')->and(BankFeedTransaction::count())->toBe(0);
})->with([false, true]);

it('rechecks connection state after the provider response', function (): void {
    Http::fake(function () {
        $this->feedConnection->update(['status' => 'inactive']);

        return Http::response(['transactions' => [$this->feedRow]]);
    });
    expect(fn () => app(BankFeedService::class)->importTransactions($this->feedConnection))->toThrow(LogicException::class, 'active');
    expect(Transaction::count())->toBe(0)->and($this->feedConnection->fresh()->last_synced_at)->toBeNull();
});

it('redacts failed provider response and connection exception details', function (bool $exception): void {
    Log::spy();
    Http::fake(function () use ($exception) {
        if ($exception) {
            throw new ConnectionException('synthetic-secret-provider-payload');
        }

        return Http::response(['error' => 'synthetic-secret-provider-payload'], 500);
    });
    expect(fn () => app(BankFeedService::class)->importTransactions($this->feedConnection))->toThrow(RuntimeException::class, 'Bank feed request failed.');
    Log::shouldHaveReceived('error')->once()->with('Bank feed request failed.', Mockery::on(fn (array $context): bool => array_keys($context) === ['exception_type'] && ! str_contains(json_encode($context), 'synthetic-secret')));
    expect(Transaction::count())->toBe(0)->and($this->feedConnection->fresh()->last_synced_at)->toBeNull();
})->with([false, true]);

it('keeps description categories as suggestions without creating ledger postings', function (string $description, string $category): void {
    Http::fake(['feed.example.test/*' => Http::response(['transactions' => [array_replace($this->feedRow, ['description' => $description])]])]);
    app(BankFeedService::class)->importTransactions($this->feedConnection);
    expect(Transaction::sole()->category)->toBe($category)->and(Transaction::sole()->status)->toBe('pending');
    $this->assertDatabaseCount('journal_entries', 0);
})->with(['salary' => ['Payroll', 'salary'], 'utilities' => ['Water supply', 'utilities'], 'food' => ['Grocery', 'food'], 'rent' => ['Lease', 'rent'], 'transport' => ['Parking', 'transportation'], 'entertainment' => ['Cinema movie', 'entertainment'], 'shopping' => ['Store', 'shopping'], 'healthcare' => ['Pharmacy', 'healthcare'], 'unknown' => ['Synthetic activity', 'uncategorized']]);
