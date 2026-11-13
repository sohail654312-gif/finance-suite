<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\ReminderSetting;
use App\Notifications\PaymentReminderNotification;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    #[\Override]
    protected $signature = 'invoices:send-reminders';

    #[\Override]
    protected $description = 'Send payment reminders for overdue invoices';

    public function handle(): void
    {
        $settings = ReminderSetting::query()->whereNotNull('team_id')->orderBy('id')->get()->unique('team_id')->filter->is_active;

        if ($settings->isEmpty()) {
            $this->info('Reminder system is not active');

            return;
        }

        foreach ($settings as $setting) {
            $invoices = Invoice::query()
                ->where('team_id', $setting->team_id)
                ->where('payment_status', 'pending')
                ->whereDate('due_date', '<', today())
                ->whereDate('invoice_date', '<=', today()->subDays($setting->days_before_reminder))
                ->where('reminders_sent', '<', $setting->max_reminders)
                ->where(fn ($query) => $query->whereNull('last_reminder_sent_at')
                    ->orWhere('last_reminder_sent_at', '<=', now()->subDays($setting->reminder_frequency_days)))
                ->with('customer');

            foreach ($invoices->lazyById() as $invoice) {
                $customer = $invoice->customer;
                if ($customer === null || (int) $customer->team_id !== (int) $setting->team_id || ! $customer->email) {
                    continue;
                }
                $customer->notify(new PaymentReminderNotification($invoice));
                $invoice->reminders_sent++;
                $invoice->last_reminder_sent_at = now();
                $invoice->save();
                $this->info("Reminder sent for Invoice #{$invoice->id}");
            }
        }
    }
}
