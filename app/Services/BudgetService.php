<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Budget;

class BudgetService
{
    /**
     * The acting user's current team, or -1 when there is none — a sentinel that
     * matches no row (team ids are positive), so a tenantless call returns empty
     * rather than leaking every unassigned (team_id IS NULL) row. Mirrors
     * GeneralLedgerService::scopedTeamId().
     */
    private function scopedTeamId(): int
    {
        return auth()->user()->current_team_id ?? -1;
    }

    public function getBudgetComparison($startDate, $endDate)
    {
        // Include budgets spanning the requested range as well as partial overlaps.
        $budgets = Budget::where('team_id', $this->scopedTeamId())
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->get();

        return $budgets->map(function ($budget): array {
            $account = $budget->account;
            $actualAmount = $this->calculateActualAmount($account, $budget);
            $variance = $actualAmount - $budget->planned_amount;
            $percentageUsed = $this->calculatePercentageUsed($actualAmount, $budget->planned_amount);

            return [
                'account_name' => $account->account_name ?? 'Unknown',
                'planned_amount' => $budget->planned_amount,
                'actual_amount' => $actualAmount,
                'forecast_amount' => $budget->forecast_amount,
                'variance' => $variance,
                'percentage_used' => round($percentageUsed, 2),
                'start_date' => $budget->start_date,
                'end_date' => $budget->end_date,
            ];
        });
    }

    public function generateForecast(Budget $budget)
    {
        $account = $budget->account;
        $historicalData = $this->getHistoricalData($account);

        // Simple moving average forecast
        $forecastAmount = $this->calculateMovingAverage($historicalData);

        $budget->forecast_amount = $forecastAmount;
        $budget->forecast_method = 'moving_average';
        $budget->save();

        return $forecastAmount;
    }

    private function getHistoricalData($account)
    {
        return $account->transactions()
            ->select('transaction_date', 'amount')
            ->orderBy('transaction_date', 'desc')
            ->limit(12)
            ->get();
    }

    private function calculateMovingAverage($historicalData)
    {
        return $historicalData->avg('amount');
    }

    private function calculateActualAmount($account, $budget): int|float
    {
        if (! $account) {
            return 0;
        }

        return $budget->getActualAmount();
    }

    private function calculatePercentageUsed(float|int $actualAmount, $plannedAmount): float|int
    {
        return $plannedAmount != 0 ? ($actualAmount / $plannedAmount) * 100 : 0;
    }
}
