<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ProjectReportService;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->projectUser = User::factory()->create();
    $this->projectTeam = app(TeamManagementService::class)->createPersonalTeamForUser($this->projectUser);
    $this->actingAs($this->projectUser->fresh());
    $this->project = Project::factory()->create([
        'team_id' => $this->projectTeam->getKey(),
        'name' => 'Synthetic research project',
        'code' => 'PRJ-SYNTH',
    ]);
});

it('reports an empty project without inventing revenue, costs, or percentage returns', function (): void {
    $service = app(ProjectReportService::class);
    $report = $service->getProjectFinancials($this->project, '2026-10-01', '2026-10-31');

    expect($report['project_name'])->toBe('Synthetic research project')
        ->and($report['project_code'])->toBe('PRJ-SYNTH')
        ->and($report['period'])->toBe(['start' => '2026-10-01', 'end' => '2026-10-31'])
        ->and((float) $report['summary']['revenue'])->toBe(0.0)
        ->and((float) $report['summary']['direct_costs'])->toBe(0.0)
        ->and((float) $report['summary']['indirect_costs'])->toBe(0.0)
        ->and((float) $report['summary']['total_costs'])->toBe(0.0)
        ->and((float) $report['summary']['gross_profit'])->toBe(0.0)
        ->and((float) $report['summary']['profit_margin'])->toBe(0.0)
        ->and($report['transactions'])->toBeEmpty()
        ->and($report['expenses'])->toBeEmpty();

    $variance = $service->getBudgetVariance($this->project, '2026-10-01', '2026-10-31');
    expect((float) $variance['budget_amount'])->toBe(0.0)
        ->and((float) $variance['actual_amount'])->toBe(0.0)
        ->and((float) $variance['variance'])->toBe(0.0)
        ->and((float) $variance['variance_percentage'])->toBe(0.0);
});

it('reconciles project revenue, direct and allocated indirect costs, and budget variance for the requested month', function (): void {
    $team = $this->projectTeam->getKey();
    $project = $this->project;
    $account = Account::factory()->create(['team_id' => $team]);

    foreach ([
        ['2026-10-10', 'credit', '500.00'],
        ['2026-10-11', 'debit', '800.00'],
        ['2026-09-28', 'credit', '900.00'],
    ] as [$date, $type, $amount]) {
        Transaction::factory()->create([
            'project_id' => $project->getKey(),
            'team_id' => $team,
            'account_id' => $account->getKey(),
            'transaction_date' => $date,
            'type' => $type,
            'amount' => $amount,
        ]);
    }

    foreach ([
        ['2026-10-15', '100.00', false, '100.00'],
        ['2026-10-16', '100.00', true, '50.00'],
        ['2026-09-30', '900.00', false, '100.00'],
    ] as [$date, $amount, $indirect, $allocation]) {
        Expense::create([
            'project_id' => $project->getKey(),
            'user_id' => $this->projectUser->getKey(),
            'team_id' => $team,
            'date' => $date,
            'amount' => $amount,
            'is_indirect' => $indirect,
            'allocation_percentage' => $allocation,
            'description' => 'Synthetic cost',
        ]);
    }

    Budget::factory()->create([
        'team_id' => $team,
        'account_id' => $account->getKey(),
        'project_id' => $project->getKey(),
        'planned_amount' => '300.00',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
    ]);

    $report = app(ProjectReportService::class)->getProjectFinancials($project, '2026-10-01', '2026-10-31');
    expect((float) $report['summary']['revenue'])->toBe(500.0)
        ->and((float) $report['summary']['direct_costs'])->toBe(100.0)
        ->and((float) $report['summary']['indirect_costs'])->toBe(50.0)
        ->and((float) $report['summary']['total_costs'])->toBe(150.0)
        ->and((float) $report['summary']['gross_profit'])->toBe(350.0)
        ->and((float) $report['summary']['profit_margin'])->toBe(70.0)
        ->and($report['transactions'])->toHaveCount(2)
        ->and($report['expenses'])->toHaveCount(2);

    $variance = app(ProjectReportService::class)->getBudgetVariance($project, '2026-10-01', '2026-10-31');
    expect((float) $variance['budget_amount'])->toBe(300.0)
        ->and((float) $variance['actual_amount'])->toBe(150.0)
        ->and((float) $variance['variance'])->toBe(150.0)
        ->and((float) $variance['variance_percentage'])->toBe(50.0);
});

it('never includes other-company rows fraudulently linked to the same project', function (): void {
    $otherUser = User::factory()->create();
    $otherTeam = app(TeamManagementService::class)->createPersonalTeamForUser($otherUser);
    $foreignAccount = Account::factory()->create(['team_id' => $otherTeam->getKey()]);

    Transaction::factory()->create([
        'project_id' => $this->project->getKey(),
        'team_id' => $otherTeam->getKey(),
        'account_id' => $foreignAccount->getKey(),
        'type' => 'credit',
        'transaction_date' => '2026-10-05',
        'amount' => '9999.00',
    ]);
    Expense::create([
        'project_id' => $this->project->getKey(),
        'user_id' => $otherUser->getKey(),
        'team_id' => $otherTeam->getKey(),
        'date' => '2026-10-06',
        'amount' => '9999.00',
        'is_indirect' => false,
    ]);
    Budget::factory()->create([
        'project_id' => $this->project->getKey(),
        'team_id' => $otherTeam->getKey(),
        'account_id' => $foreignAccount->getKey(),
        'planned_amount' => '9999.00',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
    ]);

    $service = app(ProjectReportService::class);
    $report = $service->getProjectFinancials($this->project, '2026-10-01', '2026-10-31');
    expect((float) $report['summary']['revenue'])->toBe(0.0)
        ->and((float) $report['summary']['total_costs'])->toBe(0.0)
        ->and($report['transactions'])->toBeEmpty()
        ->and($report['expenses'])->toBeEmpty();

    $variance = $service->getBudgetVariance($this->project, '2026-10-01', '2026-10-31');
    expect((float) $variance['budget_amount'])->toBe(0.0)
        ->and((float) $variance['actual_amount'])->toBe(0.0);
});
