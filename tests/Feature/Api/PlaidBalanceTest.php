<?php

namespace Tests\Feature\Api;

use App\Models\BankAccountBalance;
use App\Models\BankConnection;
use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaidBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected BankConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        app(TeamManagementService::class)->createPersonalTeamForUser($this->user);
        $this->user = $this->user->fresh();
        $this->connection = BankConnection::factory()->create([
            'team_id' => $this->user->current_team_id,
            'plaid_access_token' => 'access-test-token',
            'status' => 'active',
        ]);
    }

    public function test_get_balances_syncs_account_balances(): void
    {
        Http::fake([
            'sandbox.plaid.com/accounts/balance/get' => Http::response([
                'accounts' => [
                    [
                        'account_id' => 'acc_123',
                        'name' => 'Checking Account',
                        'type' => 'depository',
                        'subtype' => 'checking',
                        'balances' => [
                            'current' => 1250.50,
                            'available' => 1200.00,
                            'iso_currency_code' => 'USD',
                        ],
                    ],
                    [
                        'account_id' => 'acc_456',
                        'name' => 'Credit Card',
                        'type' => 'credit',
                        'subtype' => 'credit card',
                        'balances' => [
                            'current' => -350.75,
                            'available' => 4649.25,
                            'limit' => 5000.00,
                            'iso_currency_code' => 'USD',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/plaid/connections/{$this->connection->id}/balances");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'accounts' => [
                    '*' => [
                        'id',
                        'account_name',
                        'account_type',
                        'account_subtype',
                        'current_balance',
                        'available_balance',
                        'currency',
                    ],
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);

        // Verify balances were stored in database
        $this->assertDatabaseHas('bank_account_balances', [
            'bank_connection_id' => $this->connection->id,
            'plaid_account_id' => 'acc_123',
            'account_name' => 'Checking Account',
            'current_balance' => 1250.50,
        ]);

        $this->assertDatabaseHas('bank_account_balances', [
            'bank_connection_id' => $this->connection->id,
            'plaid_account_id' => 'acc_456',
            'account_name' => 'Credit Card',
            'limit_amount' => 5000.00,
        ]);
    }

    public function test_get_balances_updates_existing_balances(): void
    {
        // Create existing balance
        $existingBalance = BankAccountBalance::create([
            'bank_connection_id' => $this->connection->id,
            'plaid_account_id' => 'acc_123',
            'account_name' => 'Checking Account',
            'account_type' => 'depository',
            'current_balance' => 1000.00,
            'available_balance' => 950.00,
            'iso_currency_code' => 'USD',
            'last_updated_at' => now()->subDay(),
        ]);

        Http::fake([
            'sandbox.plaid.com/accounts/balance/get' => Http::response([
                'accounts' => [
                    [
                        'account_id' => 'acc_123',
                        'name' => 'Checking Account',
                        'type' => 'depository',
                        'subtype' => 'checking',
                        'balances' => [
                            'current' => 1500.00,
                            'available' => 1450.00,
                            'iso_currency_code' => 'USD',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/plaid/connections/{$this->connection->id}/balances");

        // Verify balance was updated
        $existingBalance->refresh();
        $this->assertEquals(1500.00, $existingBalance->current_balance);
        $this->assertEquals(1450.00, $existingBalance->available_balance);
        $this->assertEquals($this->connection->team_id, $existingBalance->team_id);
    }

    public function test_rejected_balance_batch_does_not_commit_earlier_accounts(): void
    {
        $account = [
            'account_id' => 'acc_valid', 'name' => 'Checking', 'type' => 'depository',
            'balances' => ['current' => '50.25', 'iso_currency_code' => 'USD'],
        ];
        $invalidAccounts = [
            ['named' => $account],
            [$account, $account],
            [$account, 'unavailable'],
            [$account, array_replace($account, ['account_id' => 'acc_bad', 'balances' => ['current' => '0.001']])],
            [$account, array_replace($account, ['account_id' => 'acc_bad', 'balances' => ['iso_currency_code' => 'US']])],
            [$account, array_replace($account, ['account_id' => 'acc_bad', 'subtype' => ['unexpected']])],
            [$account, array_replace($account, ['account_id' => '', 'name' => 'Missing identity'])],
        ];

        $responses = Http::sequence();
        foreach ($invalidAccounts as $accounts) {
            $responses->push(['accounts' => $accounts]);
        }
        Http::fake(['sandbox.plaid.com/accounts/balance/get' => $responses]);
        $this->actingAs($this->user);
        foreach ($invalidAccounts as $_) {
            $this->getJson("/api/plaid/connections/{$this->connection->id}/balances")
                ->assertStatus(500)->assertExactJson(['success' => false, 'message' => 'Failed to get balances.']);
            $this->assertDatabaseCount('bank_account_balances', 0);
        }
    }

    public function test_balance_provider_failure_leaves_persisted_snapshot_unchanged(): void
    {
        $balance = BankAccountBalance::create([
            'bank_connection_id' => $this->connection->id, 'plaid_account_id' => 'acc_prior',
            'account_name' => 'Checking', 'account_type' => 'depository', 'current_balance' => '12.75',
        ]);
        Http::fake(['sandbox.plaid.com/accounts/balance/get' => Http::response(['error' => 'private-provider-detail'], 400)]);

        $this->actingAs($this->user)->getJson("/api/plaid/connections/{$this->connection->id}/balances")
            ->assertStatus(500)->assertExactJson(['success' => false, 'message' => 'Failed to get balances.']);
        $this->assertSame('12.75', $balance->refresh()->current_balance);
    }

    public function test_get_balances_prevents_cross_team_access(): void
    {
        $otherUser = User::factory()->create();
        app(TeamManagementService::class)->createPersonalTeamForUser($otherUser);

        $response = $this->actingAs($otherUser->fresh())
            ->getJson("/api/plaid/connections/{$this->connection->id}/balances");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized',
            ]);
    }

    public function test_get_balances_rejects_inactive_connection(): void
    {
        $this->connection->update(['status' => 'disconnected']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/plaid/connections/{$this->connection->id}/balances");

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Connection is not active',
            ]);
    }

    public function test_bank_connection_has_balances_relationship(): void
    {
        BankAccountBalance::create([
            'bank_connection_id' => $this->connection->id,
            'plaid_account_id' => 'acc_123',
            'account_name' => 'Test Account',
            'account_type' => 'depository',
            'current_balance' => 1000.00,
            'iso_currency_code' => 'USD',
        ]);

        $this->assertCount(1, $this->connection->balances);
        $this->assertEquals('Test Account', $this->connection->balances->first()->account_name);
    }
}
