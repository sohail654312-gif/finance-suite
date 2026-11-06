<?php

use App\Models\BankConnection;
use App\Services\PlaidService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.plaid.client_id' => 'synthetic-client', 'services.plaid.secret' => 'synthetic-secret', 'services.plaid.environment' => 'sandbox']);
});

it('redacts rejected provider responses and transport errors across every public request', function (string $method): void {
    Log::spy();
    Http::fake(['sandbox.plaid.com/*' => Http::response(['error_message' => 'synthetic-private-token'], 400)]);
    $service = new PlaidService();
    $connection = BankConnection::factory()->create(['plaid_access_token' => 'synthetic-access', 'plaid_cursor' => 'previous']);
    try {
        match ($method) {
            'link' => $service->createLinkToken(123),
            'exchange' => $service->exchangePublicToken('synthetic-public'),
            'institution' => $service->getInstitution('synthetic-bank'),
            'sync' => $service->syncTransactions($connection),
            'accounts' => $service->getAccounts('synthetic-access'),
            'balances' => $service->getBalances('synthetic-access'),
            'remove' => $service->removeItem('synthetic-access'),
        };
        $this->fail('A rejected request must fail explicitly.');
    } catch (Exception $error) {
        expect($error->getMessage())->toStartWith('Failed to ')->not->toContain('synthetic-private-token');
        expect($error->getPrevious())->toBeNull();
    }
    expect($connection->fresh()->plaid_cursor)->toBe('previous');
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context): bool => isset($context['exception_type']) && ! str_contains(json_encode($context), 'synthetic-private-token'));
})->with(['link', 'exchange', 'institution', 'sync', 'accounts', 'balances', 'remove']);

it('retains only known reauthentication codes and never reflects provider-controlled errors', function (string $code, bool $requiresReauth): void {
    Log::spy();
    Http::fake(['sandbox.plaid.com/*' => Http::response(['error_code' => $code, 'error_type' => 'synthetic-private-token', 'error_message' => 'synthetic-private-token'], 400)]);
    $connection = BankConnection::factory()->create(['plaid_access_token' => 'synthetic-access']);
    try {
        (new PlaidService())->syncTransactions($connection);
        $this->fail('A rejected sync must fail explicitly.');
    } catch (Exception $error) {
        expect(str_starts_with($error->getMessage(), 'User action required:'))->toBe($requiresReauth);
        expect($error->getMessage())->not->toContain('synthetic-private-token');
    }
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context): bool => str_starts_with($message, 'Plaid API error')
        && ! str_contains(json_encode($context), 'synthetic-private-token'));
    Http::assertSentCount(1);
})->with([
    ['ITEM_LOGIN_REQUIRED', true], ['INVALID_CREDENTIALS', true], ['INVALID_MFA', true], ['ITEM_LOCKED', true], ['synthetic-private-token', false],
]);

it('retries transient provider failures and commits the advancing cursor after success', function (): void {
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::sequence()->push(['error_message' => 'synthetic-private-token'], 503)->push(['added' => [], 'modified' => [], 'removed' => [], 'next_cursor' => 'advanced', 'has_more' => false])]);
    $connection = BankConnection::factory()->create(['plaid_access_token' => 'synthetic-access', 'plaid_cursor' => 'previous']);
    expect((new PlaidService())->syncTransactions($connection)['next_cursor'])->toBe('advanced');
    expect($connection->fresh()->plaid_cursor)->toBe('advanced')->and($connection->fresh()->last_synced_at)->not->toBeNull();
    Http::assertSentCount(2);
});

it('sends the configured OAuth redirect and existing token only in explicit update mode', function (): void {
    config(['services.plaid.oauth_redirect_uri' => 'https://synthetic.example/callback']);
    Http::fake(['sandbox.plaid.com/link/token/create' => Http::response(['link_token' => 'synthetic-link'])]);
    expect((new PlaidService())->createLinkToken(123, 'en', 'synthetic-existing')['link_token'])->toBe('synthetic-link');
    Http::assertSent(fn ($request): bool => $request['redirect_uri'] === 'https://synthetic.example/callback' && $request['access_token'] === 'synthetic-existing' && $request['user']['client_user_id'] === '123');
});
