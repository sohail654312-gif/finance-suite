<?php

declare(strict_types=1);

use App\Models\BankConnection;
use App\Models\BankFeedTransaction;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['finance-suite.demo' => false, 'services.plaid.environment' => 'sandbox',
        'services.plaid.client_id' => 'synthetic-client', 'services.plaid.secret' => 'synthetic-secret']);
    $this->entryOwner = User::factory()->create();
    $this->entryTeam = app(TeamManagementService::class)->createPersonalTeamForUser($this->entryOwner);
    $this->entryOwner->refresh();
    $this->entryCurrency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'is_default' => true]);
    $this->entryConnection = BankConnection::create(['bank_id' => 'ins_123', 'user_id' => $this->entryOwner->id,
        'team_id' => $this->entryTeam->id, 'plaid_access_token' => 'synthetic-access', 'plaid_item_id' => 'synthetic-item',
        'plaid_institution_id' => 'ins_123', 'status' => 'active']);
    $this->entryRow = ['transaction_id' => 'synthetic-entry-1', 'date' => '2026-11-06', 'amount' => '-15.25',
        'iso_currency_code' => 'USD', 'pending' => false, 'name' => 'Synthetic receipt'];
    $this->actingAs($this->entryOwner);
});

function plaidEntryPage(array $added = [], array $modified = [], string $cursor = 'synthetic-final', bool $more = false): array
{
    return ['added' => $added, 'modified' => $modified, 'removed' => [], 'next_cursor' => $cursor, 'has_more' => $more];
}

it('imports every page for a connection created through the public Plaid link endpoint', function (): void {
    Http::fake([
        'sandbox.plaid.com/item/public_token/exchange' => Http::response(['access_token' => 'synthetic-link-access', 'item_id' => 'synthetic-linked-item']),
        'sandbox.plaid.com/transactions/sync' => Http::sequence()
            ->push(plaidEntryPage([$this->entryRow], cursor: 'synthetic-page-one', more: true))
            ->push(plaidEntryPage([[...$this->entryRow, 'transaction_id' => 'synthetic-entry-2', 'amount' => '0.10']])),
    ]);
    $connectionId = $this->postJson('/api/plaid/store-connection', [
        'public_token' => 'synthetic-public-token', 'institution_id' => 'ins_456', 'institution_name' => 'Synthetic Bank',
        'accounts' => [['id' => 'synthetic-account', 'name' => 'Checking']],
    ])->assertCreated()->json('connection.id');
    $connection = BankConnection::findOrFail($connectionId);

    $this->postJson('/api/plaid/connections/'.$connection->id.'/sync')->assertOk()
        ->assertJsonPath('success', true)->assertJsonPath('summary.added', 2)->assertJsonPath('summary.total_processed', 2)
        ->assertJsonPath('summary.modified', 0)->assertJsonPath('summary.removed', 0);
    expect(Transaction::count())->toBe(2)->and(BankFeedTransaction::count())->toBe(2)
        ->and(Transaction::where('external_id', 'synthetic-entry-1')->sole()->amount)->toBe('15.25')
        ->and(Transaction::where('external_id', 'synthetic-entry-1')->sole()->currency_id)->toBe($this->entryCurrency->currency_id)
        ->and($connection->fresh()->plaid_cursor)->toBe('synthetic-final');
    Http::assertSentCount(3);
});

it('does not allow the public endpoint to change reconciled money or commit its rejected cursor', function (): void {
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::sequence()
        ->push(plaidEntryPage([$this->entryRow]))
        ->push(plaidEntryPage(modified: [[...$this->entryRow, 'amount' => '900.00']], cursor: 'rejected-cursor'))]);
    $url = '/api/plaid/connections/'.$this->entryConnection->id.'/sync';
    $this->postJson($url)->assertOk();
    $transaction = Transaction::sole();
    $transaction->update(['reconciled' => true]);
    $this->postJson($url)->assertStatus(500)->assertExactJson(['success' => false, 'message' => 'Failed to sync transactions.']);
    expect($transaction->fresh()->amount)->toBe('15.25')->and($transaction->fresh()->reconciled)->toBeTrue()
        ->and($this->entryConnection->fresh()->plaid_cursor)->toBe('synthetic-final');
});

it('requires current accounting authority for public sync even when a caller has selected the same team', function (string $state): void {
    $actor = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($actor);
    $this->entryTeam->users()->attach($actor->id, ['role' => $state === 'viewer' ? 'viewer' : 'accountant']);
    $actor->switchTeam($this->entryTeam);
    if ($state === 'revoked') {
        $this->entryTeam->users()->detach($actor->id);
    }
    $this->actingAs($actor);
    $this->postJson('/api/plaid/connections/'.$this->entryConnection->id.'/sync')->assertForbidden()
        ->assertJsonPath('success', false);
    Http::assertNothingSent();
    expect(Transaction::count())->toBe(0)->and($this->entryConnection->fresh()->plaid_cursor)->toBeNull();
})->with(['viewer', 'revoked']);

it('fails a rejected public sync safely without reflecting provider-controlled credentials', function (): void {
    Http::fake(['sandbox.plaid.com/transactions/sync' => Http::response([
        'error_code' => 'synthetic-private-token', 'error_message' => 'synthetic-private-token',
    ], 400)]);
    $this->postJson('/api/plaid/connections/'.$this->entryConnection->id.'/sync')->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to sync transactions.']);
    expect(Transaction::count())->toBe(0)->and($this->entryConnection->fresh()->plaid_cursor)->toBeNull();
});
