<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Expense;
use App\Models\Project;
use App\Models\Transaction;

class ProjectReportService
{
    public function getProjectFinancials(Project $project, $startDate, $endDate): array
    {
        // Project relationships are not themselves company ownership checks:
        // a corrupt/forged project_id may point to a row from another team.
        // A missing tenant must fail closed rather than query null-team data.
        $teamId = (int) ($project->team_id ?? -1);
        $transactions = $project->transactions()
            ->where('team_id', $teamId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->get();

        $expenses = $project->expenses()
            ->where('team_id', $teamId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $directCosts = $expenses->where('is_indirect', false)->sum('amount');
        $indirectCosts = $this->calculateIndirectCosts($project, $startDate, $endDate);
        $revenue = $transactions->where('type', 'credit')->sum('amount');

        return [
            'project_name' => $project->name,
            'project_code' => $project->code,
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'summary' => [
                'revenue' => $revenue,
                'direct_costs' => $directCosts,
                'indirect_costs' => $indirectCosts,
                'total_costs' => $directCosts + $indirectCosts,
                'gross_profit' => $revenue - ($directCosts + $indirectCosts),
                'profit_margin' => $revenue > 0 ?
                    (($revenue - ($directCosts + $indirectCosts)) / $revenue) * 100 : 0,
            ],
            'transactions' => $transactions->map(fn (Transaction $t): array => [
                'date' => $t->transaction_date,
                'type' => $t->type,
                'amount' => $t->amount,
                'description' => $t->description,
            ]),
            'expenses' => $expenses->map(fn (Expense $e): array => [
                'date' => $e->date,
                'amount' => $e->amount,
                'description' => $e->description,
                'type' => $e->is_indirect ? 'Indirect' : 'Direct',
            ]),
        ];
    }

    private function calculateIndirectCosts(Project $project, $startDate, $endDate)
    {
        return $project->expenses()
            ->where('team_id', (int) ($project->team_id ?? -1))
            ->where('is_indirect', true)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->sum(fn (Expense $expense): float => $expense->getAllocatedAmount());
    }

    public function getBudgetVariance(Project $project, $startDate, $endDate): array
    {
        $actuals = $this->getProjectFinancials($project, $startDate, $endDate);
        $budgets = $project->budgets()
            ->where('team_id', (int) ($project->team_id ?? -1))
            ->whereBetween('start_date', [$startDate, $endDate])
            ->get();

        $totalBudget = $budgets->sum('planned_amount');

        return [
            'budget_amount' => $totalBudget,
            'actual_amount' => $actuals['summary']['total_costs'],
            'variance' => $totalBudget - $actuals['summary']['total_costs'],
            'variance_percentage' => $totalBudget > 0 ?
                (($totalBudget - $actuals['summary']['total_costs']) / $totalBudget) * 100 : 0,
        ];
    }
}
