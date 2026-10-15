<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Notifications\CollectionNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CollectionService
{
    /**
     * Resolve the selected accounting company. A missing or invalid selection
     * must never turn a collections query into a cross-company operation.
     */
    private function scopedTeamId(): int
    {
        $teamId = auth()->user()?->current_team_id;

        return is_numeric($teamId) && (int) $teamId > 0 ? (int) $teamId : -1;
    }

    public function flagDelinquentAccounts(): void
    {
        $teamId = $this->scopedTeamId();
        $overdueBefore = Carbon::now()->subDays(90);

        $customers = Customer::query()
            ->where('team_id', $teamId)
            ->whereHas('invoices', function ($query) use ($teamId, $overdueBefore): void {
                $query->where('team_id', $teamId)
                    ->where('payment_status', 'pending')
                    ->where('due_date', '<', $overdueBefore);
            })->get();

        foreach ($customers as $customer) {
            // A periodic collection sweep should not spam a customer on every
            // invocation after a credit hold has already been established.
            if ($customer->credit_hold) {
                continue;
            }

            $customer->credit_hold = true;
            $customer->save();

            if (! empty($customer->customer_email)) {
                $customer->notify(new CollectionNotification($customer));
            }
        }
    }

    public function generateCollectionReport(): Collection
    {
        $teamId = $this->scopedTeamId();

        return Customer::query()
            ->where('team_id', $teamId)
            ->where('credit_hold', true)
            ->with(['invoices' => function ($query) use ($teamId): void {
                $query->where('team_id', $teamId)
                    ->where('payment_status', 'pending')
                    ->where('due_date', '<', Carbon::today())
                    ->orderBy('due_date');
            }])
            ->get()
            ->map(fn (Customer $customer): array => [
                'customer_name' => $customer->customer_name,
                'total_overdue' => $customer->invoices->sum('total_amount'),
                'oldest_invoice_date' => $customer->invoices->min('due_date'),
                'number_of_invoices' => $customer->invoices->count(),
                'contact_info' => [
                    'email' => $customer->customer_email,
                    'phone' => $customer->customer_phone,
                ],
            ]);
    }
}
