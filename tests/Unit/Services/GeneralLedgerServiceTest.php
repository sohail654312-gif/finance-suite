<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\GeneralLedgerService;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class GeneralLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private GeneralLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $exchangeService = Mockery::mock(ExchangeRateService::class);
        $exchangeService->allows('getExchangeRate')->andReturn(1.0);

        $this->service = new GeneralLedgerService($exchangeService);
    }

    public function test_get_account_balances_returns_iterable(): void
    {
        // Without any accounts the service should return an empty collection, not throw.
        $result = $this->service->getAccountBalances('2024-01-01', '2024-12-31');

        $this->assertInstanceOf(Collection::class, $result);
    }

    public function test_get_trial_balance_returns_iterable(): void
    {
        $result = $this->service->getTrialBalance('2024-12-31');

        $this->assertNotNull($result);
    }

    public function test_trial_balance_has_expected_shape_when_accounts_exist(): void
    {
        $user = User::factory()->create();
        $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
        $this->actingAs($user->fresh());
        $account = Account::factory()->create(['team_id' => $team->id, 'balance' => -125]);
        $rows = $this->service->getTrialBalance('2024-12-31');
        $this->assertCount(1, $rows);
        $this->assertSame($account->id, $rows[0]['account_id']);
        $this->assertSame($account->account_name, $rows[0]['account_name']);
        $this->assertSame(0, $rows[0]['debit']);
        $this->assertSame(125.0, $rows[0]['credit']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
