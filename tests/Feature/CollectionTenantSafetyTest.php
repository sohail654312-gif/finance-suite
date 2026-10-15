<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\CollectionNotification;
use App\Services\CollectionService;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());
    $this->collectionsUser = User::factory()->create();
    $this->collectionsTeam = app(TeamManagementService::class)->createPersonalTeamForUser($this->collectionsUser);
    $this->actingAs($this->collectionsUser->fresh());
    Notification::fake();
});

it('holds only its own delinquent customers and sends a single repeat-safe notice', function (): void {
    $other = User::factory()->create();
    $foreignTeam = app(TeamManagementService::class)->createPersonalTeamForUser($other);
    $ownedCustomer = Customer::factory()->create(['team_id' => $this->collectionsTeam->getKey()]);
    $poisonedCustomer = Customer::factory()->create(['team_id' => $this->collectionsTeam->getKey()]);
    $foreignCustomer = Customer::factory()->create(['team_id' => $foreignTeam->getKey()]);

    Invoice::factory()->create([
        'team_id' => $this->collectionsTeam->getKey(),
        'customer_id' => $ownedCustomer->getKey(),
        'payment_status' => 'pending',
        'due_date' => now()->subDays(95)->toDateString(),
        'total_amount' => '100.00',
    ]);
    Invoice::factory()->create([
        'team_id' => $foreignTeam->getKey(),
        'customer_id' => $foreignCustomer->getKey(),
        'payment_status' => 'pending',
        'due_date' => now()->subDays(95)->toDateString(),
        'total_amount' => '9000.00',
    ]);
    // An attacker/legacy import links a foreign invoice to an owned customer.
    // It must not be treated as evidence for this company's credit hold.
    Invoice::factory()->create([
        'team_id' => $foreignTeam->getKey(),
        'customer_id' => $poisonedCustomer->getKey(),
        'payment_status' => 'pending',
        'due_date' => now()->subDays(95)->toDateString(),
        'total_amount' => '9000.00',
    ]);

    $service = app(CollectionService::class);
    $service->flagDelinquentAccounts();
    $service->flagDelinquentAccounts();

    expect((bool) $ownedCustomer->fresh()->credit_hold)->toBeTrue()
        ->and((bool) $poisonedCustomer->fresh()->credit_hold)->toBeFalse()
        ->and((bool) $foreignCustomer->fresh()->credit_hold)->toBeFalse()
        ->and($ownedCustomer->routeNotificationForMail())->toBe($ownedCustomer->customer_email);

    Notification::assertSentTo($ownedCustomer, CollectionNotification::class);
    Notification::assertNotSentTo($foreignCustomer, CollectionNotification::class);
    Notification::assertNotSentTo($poisonedCustomer, CollectionNotification::class);
    Notification::assertCount(1);
});

it('reports only owned pending invoices and ignores cross-company links and paid amounts', function (): void {
    $foreignUser = User::factory()->create();
    $foreignTeam = app(TeamManagementService::class)->createPersonalTeamForUser($foreignUser);
    $customer = Customer::factory()->create([
        'team_id' => $this->collectionsTeam->getKey(),
        'credit_hold' => true,
    ]);
    $foreignCustomer = Customer::factory()->create([
        'team_id' => $foreignTeam->getKey(),
        'credit_hold' => true,
    ]);

    foreach ([
        [$this->collectionsTeam->getKey(), $customer->getKey(), 'pending', '40.00', '2026-06-01'],
        [$this->collectionsTeam->getKey(), $customer->getKey(), 'pending', '25.00', '2026-07-01'],
        [$this->collectionsTeam->getKey(), $customer->getKey(), 'paid', '1000.00', '2026-01-01'],
        [$this->collectionsTeam->getKey(), $customer->getKey(), 'pending', '777.00', '2026-11-01'],
        [$foreignTeam->getKey(), $customer->getKey(), 'pending', '9000.00', '2026-01-01'],
        [$foreignTeam->getKey(), $foreignCustomer->getKey(), 'pending', '9000.00', '2026-01-01'],
    ] as [$teamId, $customerId, $status, $amount, $date]) {
        Invoice::factory()->create([
            'team_id' => $teamId,
            'customer_id' => $customerId,
            'payment_status' => $status,
            'total_amount' => $amount,
            'due_date' => $date,
        ]);
    }

    $rows = app(CollectionService::class)->generateCollectionReport();
    expect($rows)->toHaveCount(1);
    $row = $rows->sole();

    expect($row['customer_name'])->toBe($customer->customer_name)
        ->and((float) $row['total_overdue'])->toBe(65.0)
        ->and($row['number_of_invoices'])->toBe(2)
        ->and($row['oldest_invoice_date']->toDateString())->toBe('2026-06-01')
        ->and($row['contact_info']['email'])->toBe($customer->customer_email);

    $notice = new CollectionNotification($customer);
    expect($notice->toSms($customer))->toContain('$65.00')
        ->and($notice->toSms($customer))->not->toContain('777.00')
        ->and(implode(' ', $notice->toMail($customer)->introLines))->toContain('Total amount overdue: $65.00');
});

it('fails closed without a selected company and never updates another customer', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->collectionsTeam->getKey()]);
    Invoice::factory()->create([
        'team_id' => $this->collectionsTeam->getKey(),
        'customer_id' => $customer->getKey(),
        'payment_status' => 'pending',
        'due_date' => now()->subDays(120)->toDateString(),
    ]);

    $this->collectionsUser->forceFill(['current_team_id' => null])->save();
    $this->actingAs($this->collectionsUser->fresh());

    $service = app(CollectionService::class);
    $service->flagDelinquentAccounts();

    expect($service->generateCollectionReport())->toBeEmpty()
        ->and((bool) $customer->fresh()->credit_hold)->toBeFalse();
    Notification::assertNothingSent();
});
