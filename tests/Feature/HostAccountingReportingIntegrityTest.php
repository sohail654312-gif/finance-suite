<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AgingReportService;
use App\Services\BudgetService;
use App\Services\GeneralLedgerService;
use App\Services\TeamManagementService;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 7)->startOfDay());
    $this->reportUser = User::factory()->create();
    $this->reportTeam = app(TeamManagementService::class)->createPersonalTeamForUser($this->reportUser);
    $this->actingAs($this->reportUser->fresh());
});

it('calculates comparative income and seven-day charts from the acting company only', function (): void {
    $revenue = Account::factory()->create(['team_id' => $this->reportTeam->id, 'account_type' => 'revenue']);
    $expense = Account::factory()->create(['team_id' => $this->reportTeam->id, 'account_type' => 'expense']);
    $otherUser = User::factory()->create();
    $otherTeam = app(TeamManagementService::class)->createPersonalTeamForUser($otherUser);
    $foreignRevenue = Account::factory()->create(['team_id' => $otherTeam->id, 'account_type' => 'revenue']);
    foreach ([[$revenue, '2026-10-07', 1200], [$revenue, '2026-09-15', 800], [$expense, '2026-10-07', 400], [$expense, '2026-09-15', 200], [$foreignRevenue, '2026-10-07', 9999]] as [$account, $date, $amount]) {
        Transaction::factory()->create(['account_id' => $account->id, 'team_id' => $account->team_id, 'transaction_date' => $date, 'amount' => $amount]);
    }

    $metrics = app(GeneralLedgerService::class)->getKeyMetrics();
    expect((float) $metrics['revenue'])->toBe(1200.0)
        ->and((float) $metrics['previousRevenue'])->toBe(800.0)
        ->and((float) $metrics['expenses'])->toBe(400.0)
        ->and((float) $metrics['previousExpenses'])->toBe(200.0)
        ->and((float) $metrics['netIncome'])->toBe(800.0)
        ->and((float) $metrics['previousNetIncome'])->toBe(600.0)
        ->and((float) $metrics['revenueChange'])->toBe(50.0)
        ->and((float) $metrics['expensesChange'])->toBe(100.0)
        ->and(array_map('floatval', $metrics['revenueChart']))->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1200.0])
        ->and(array_map('floatval', $metrics['expensesChart']))->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 400.0])
        ->and(array_map('floatval', $metrics['netIncomeChart']))->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 800.0]);
    $this->assertEqualsWithDelta(100 / 3, $metrics['netIncomeChange'], 0.00001);
});

it('returns zero comparative changes when there is no previous activity', function (): void {
    $metrics = app(GeneralLedgerService::class)->getKeyMetrics();
    expect((float) $metrics['revenueChange'])->toBe(0.0)
        ->and((float) $metrics['expensesChange'])->toBe(0.0)
        ->and((float) $metrics['netIncomeChange'])->toBe(0.0)
        ->and($metrics['netIncomeChart'])->toHaveCount(7);
});

it('restricts budget actuals to its owned account, project and dates', function (): void {
    $account = Account::factory()->create(['team_id' => $this->reportTeam->id]);
    $otherAccount = Account::factory()->create(['team_id' => $this->reportTeam->id]);
    $project = Project::factory()->create(['team_id' => $this->reportTeam->id]);
    $budget = Budget::factory()->create(['team_id' => $this->reportTeam->id, 'account_id' => $account->id, 'project_id' => $project->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'planned_amount' => 100]);
    foreach ([[$account, $project->id, '2026-10-07', 40], [$otherAccount, $project->id, '2026-10-07', 900], [$account, null, '2026-10-07', 800], [$account, $project->id, '2026-09-30', 700]] as [$rowAccount, $projectId, $date, $amount]) {
        Transaction::factory()->create(['team_id' => $this->reportTeam->id, 'account_id' => $rowAccount->id, 'project_id' => $projectId, 'transaction_date' => $date, 'amount' => $amount]);
    }
    expect((float) $budget->getActualAmount())->toBe(40.0);
    $row = app(BudgetService::class)->getBudgetComparison('2026-10-01', '2026-10-31')->sole();
    expect((float) $row['actual_amount'])->toBe(40.0)
        ->and((float) $row['variance'])->toBe(-60.0)
        ->and((float) $row['percentage_used'])->toBe(40.0);
});

it('fails closed for a budget linked to another company account or lacking a company', function (): void {
    $otherUser = User::factory()->create();
    $otherTeam = app(TeamManagementService::class)->createPersonalTeamForUser($otherUser);
    $account = Account::factory()->create(['team_id' => $otherTeam->id]);
    Transaction::factory()->create(['team_id' => $otherTeam->id, 'account_id' => $account->id, 'transaction_date' => '2026-10-07', 'amount' => 500]);
    $budget = Budget::factory()->create(['team_id' => $this->reportTeam->id, 'account_id' => $account->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    expect($budget->getActualAmount())->toBe(0);
    $budget->forceFill(['team_id' => null])->save();
    expect($budget->getActualAmount())->toBe(0);
});

it('includes a budget spanning the requested range and handles a zero plan', function (): void {
    $account = Account::factory()->create(['team_id' => $this->reportTeam->id]);
    Budget::factory()->create(['team_id' => $this->reportTeam->id, 'account_id' => $account->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'planned_amount' => 0]);
    $row = app(GeneralLedgerService::class)->getBudgetComparison('2026-10-01', '2026-10-31')->sole();
    expect((float) $row['actual_amount'])->toBe(0.0)
        ->and((float) $row['percentage_used'])->toBe(0.0);
});

it('forecasts from the latest twelve transactions rather than all history', function (): void {
    $account = Account::factory()->create(['team_id' => $this->reportTeam->id]);
    $budget = Budget::factory()->create(['team_id' => $this->reportTeam->id, 'account_id' => $account->id]);
    Transaction::factory()->create(['team_id' => $this->reportTeam->id, 'account_id' => $account->id, 'transaction_date' => '2025-01-01', 'amount' => 900]);
    for ($day = 1; $day <= 12; $day++) {
        Transaction::factory()->create(['team_id' => $this->reportTeam->id, 'account_id' => $account->id, 'transaction_date' => sprintf('2026-09-%02d', $day), 'amount' => $day * 10]);
    }
    expect((float) app(BudgetService::class)->generateForecast($budget))->toBe(65.0)
        ->and($budget->fresh()->forecast_amount)->toBe('65.00')
        ->and($budget->fresh()->forecast_method)->toBe('moving_average');
});

it('scopes receivables aging to invoices whose company and customer are both owned', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->reportTeam->id]);
    $otherUser = User::factory()->create();
    $otherTeam = app(TeamManagementService::class)->createPersonalTeamForUser($otherUser);
    $otherCustomer = Customer::factory()->create(['team_id' => $otherTeam->id]);

    Invoice::factory()->create([
        'team_id' => $this->reportTeam->id,
        'customer_id' => $customer->getKey(),
        'due_date' => '2026-10-06',
        'total_amount' => '100.00',
    ]);
    Invoice::factory()->create([
        'team_id' => $otherTeam->id,
        'customer_id' => $otherCustomer->getKey(),
        'due_date' => '2026-10-06',
        'total_amount' => '900.00',
    ]);
    Invoice::factory()->create([
        'team_id' => $this->reportTeam->id,
        'customer_id' => $otherCustomer->getKey(),
        'due_date' => '2026-10-06',
        'total_amount' => '700.00',
    ]);

    $aging = app(AgingReportService::class)->generateAgingReport();
    expect($aging)->toHaveCount(1)
        ->and($aging->get($customer->getKey())['aging']['1_30'])->toBe(100.0)
        ->and($aging->get($customer->getKey())['total_due'])->toBe(100.0);
});

it('classifies future and overdue receivables into the proper nonoverlapping age bands', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->reportTeam->id]);
    foreach ([-5, 1, 31, 61, 91] as $daysOverdue) {
        Invoice::factory()->create([
            'team_id' => $this->reportTeam->id,
            'customer_id' => $customer->getKey(),
            'due_date' => now()->startOfDay()->subDays($daysOverdue)->toDateString(),
            'total_amount' => '10.00',
        ]);
    }

    $row = app(AgingReportService::class)->generateAgingReport()->get($customer->getKey());
    expect($row['aging'])->toBe([
        'current' => 10.0,
        '1_30' => 10.0,
        '31_60' => 10.0,
        '61_90' => 10.0,
        'over_90' => 10.0,
    ])->and($row['total_due'])->toBe(50.0);
});

it('fails closed when the reporting user has no selected company', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->reportTeam->id]);
    Invoice::factory()->create(['team_id' => $this->reportTeam->id, 'customer_id' => $customer->getKey()]);

    $this->reportUser->forceFill(['current_team_id' => null])->save();
    $this->actingAs($this->reportUser->fresh());

    expect(app(AgingReportService::class)->generateAgingReport())->toBeEmpty();
});
