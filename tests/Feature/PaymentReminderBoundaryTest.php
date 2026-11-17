<?php

declare(strict_types=1);

use App\Filament\App\Resources\ReminderSettings\Pages\EditReminderSetting;
use App\Filament\App\Resources\ReminderSettings\Pages\ListReminderSettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Notifications\PaymentReminderNotification;
use App\Services\TeamManagementService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('sends overdue reminders only under the invoice company policy and never to another company customer', function (): void {
    Notification::fake();
    $teams = app(TeamManagementService::class);
    $first = $teams->createPersonalTeamForUser(User::factory()->create());
    $second = $teams->createPersonalTeamForUser(User::factory()->create());
    $mine = Customer::factory()->create(['team_id' => $first->getKey(), 'customer_email' => 'mine@example.test']);
    $foreign = Customer::factory()->create(['team_id' => $second->getKey(), 'customer_email' => 'foreign@example.test']);
    $settings = ['days_before_reminder' => 3, 'reminder_frequency_days' => 7, 'max_reminders' => 2];
    ReminderSetting::forceCreate($settings + ['team_id' => $first->getKey(), 'is_active' => true]);
    ReminderSetting::forceCreate($settings + ['team_id' => $second->getKey(), 'is_active' => false]);
    $fields = ['invoice_date' => now()->subDays(20), 'due_date' => now()->subDays(2), 'payment_status' => 'pending'];
    $overdue = Invoice::factory()->create($fields + ['team_id' => $first->getKey(), 'customer_id' => $mine->getKey()]);
    $wrongCustomer = Invoice::factory()->create($fields + ['team_id' => $first->getKey(), 'customer_id' => $foreign->getKey()]);
    $disabled = Invoice::factory()->create($fields + ['team_id' => $second->getKey(), 'customer_id' => $foreign->getKey()]);
    $capped = Invoice::factory()->create($fields + ['team_id' => $first->getKey(), 'customer_id' => $mine->getKey(), 'reminders_sent' => 2]);
    $notDue = Invoice::factory()->create(array_replace($fields, ['team_id' => $first->getKey(), 'customer_id' => $mine->getKey(), 'due_date' => now()->addDay()]));

    $this->artisan('invoices:send-reminders')->assertSuccessful();
    $this->artisan('invoices:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($mine, PaymentReminderNotification::class, 1);
    Notification::assertNotSentTo($foreign, PaymentReminderNotification::class);
    expect((int) $overdue->fresh()->reminders_sent)->toBe(1)
        ->and((int) $wrongCustomer->fresh()->reminders_sent)->toBe(0)
        ->and((int) $disabled->fresh()->reminders_sent)->toBe(0)
        ->and((int) $notDue->fresh()->reminders_sent)->toBe(0)
        ->and((int) $capped->fresh()->reminders_sent)->toBe(2);
});

it('configures reminder settings for the active team and keeps foreign policies out of the list', function (): void {
    $teams = app(TeamManagementService::class);
    $owner = User::factory()->create();
    $team = $teams->createPersonalTeamForUser($owner);
    $foreign = $teams->createPersonalTeamForUser(User::factory()->create());
    $foreignPolicy = ReminderSetting::create(['team_id' => $foreign->getKey(), 'is_active' => true]);
    $this->actingAs($owner->fresh());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);

    Livewire::test(ListReminderSettings::class)
        ->assertCanNotSeeTableRecords([$foreignPolicy])
        ->callAction('configure')->assertHasNoActionErrors();
    $policy = ReminderSetting::query()->where('team_id', $team->getKey())->sole();
    expect($policy->is_active)->toBeFalse();
    Livewire::test(EditReminderSetting::class, ['record' => $policy->getRouteKey()])
        ->fillForm(['days_before_reminder' => 5, 'reminder_frequency_days' => 7, 'max_reminders' => 3, 'is_active' => true])
        ->call('save')->assertHasNoFormErrors();
    expect((int) $policy->fresh()->days_before_reminder)->toBe(5)
        ->and($policy->fresh()->is_active)->toBeTrue()
        ->and((int) $foreignPolicy->fresh()->days_before_reminder)->toBe(3);
});
