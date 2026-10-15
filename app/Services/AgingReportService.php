<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AgingReportService
{
    public function generateAgingReport(): Collection
    {
        $today = Carbon::today();
        $teamId = auth()->user()?->current_team_id;

        if (! is_numeric($teamId) || (int) $teamId < 1) {
            return collect();
        }

        return Invoice::query()
            ->where('team_id', (int) $teamId)
            ->whereHas('customer', fn ($query) => $query->where('team_id', (int) $teamId))
            ->where('payment_status', 'pending')
            ->with('customer')
            ->get()
            ->groupBy('customer_id')
            ->map(function ($invoices) use ($today): array {
                $customer = $invoices->first()->customer;

                $aging = [
                    'current' => 0,
                    '1_30' => 0,
                    '31_60' => 0,
                    '61_90' => 0,
                    'over_90' => 0,
                ];

                foreach ($invoices as $invoice) {
                    $daysOverdue = $invoice->due_date?->diffInDays($today, false) ?? 0;
                    $amount = $invoice->getTotalWithTax();

                    if ($daysOverdue <= 0) {
                        $aging['current'] += $amount;
                    } elseif ($daysOverdue <= 30) {
                        $aging['1_30'] += $amount;
                    } elseif ($daysOverdue <= 60) {
                        $aging['31_60'] += $amount;
                    } elseif ($daysOverdue <= 90) {
                        $aging['61_90'] += $amount;
                    } else {
                        $aging['over_90'] += $amount;
                    }
                }

                return [
                    'customer_name' => $customer->customer_name,
                    'customer_id' => $customer->customer_id,
                    'credit_limit' => $customer->credit_limit,
                    'current_balance' => $customer->current_balance,
                    'aging' => $aging,
                    'total_due' => array_sum($aging),
                ];
            });
    }
}
