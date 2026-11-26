<?php

declare(strict_types=1);

namespace Tests\Feature\PostingReversal;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\PaymentPostingService;
use App\Services\PostingReversalService;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecomputeStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_recompute_reflects_posted_payments_in_both_directions(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $this->actingAs($user->fresh());
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 500, 'payment_status' => 'pending']);
        $posted = Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => 500, 'payment_date' => '2026-06-05', 'team_id' => $team->id]);
        $entry = app(PaymentPostingService::class)->post($posted);
        $this->assertTrue($entry->is_posted);
        $this->assertTrue($entry->isBalanced());
        Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => 100, 'payment_date' => '2026-06-05', 'team_id' => $team->id]);

        $invoice->recomputePaymentStatus();
        $this->assertSame('paid', $invoice->fresh()->payment_status);

        app(PostingReversalService::class)->reversePayment($posted);
        $invoice->recomputePaymentStatus();
        $this->assertSame('pending', $invoice->fresh()->payment_status);
        $this->assertTrue($entry->fresh()->is_posted);
        $this->assertNotNull($entry->reversal()->first());
    }
}
