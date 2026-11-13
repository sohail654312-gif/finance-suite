<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\InventoryItem;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-tenant leak regression: inventory listings/valuations must not enumerate
 * another team's items. InventoryItem uses the inert IsTenantModel trait (no
 * global scope) so isolation is explicit via ->where('team_id', ...).
 */
class InventoryTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: InventoryItem, 2: InventoryItem} acting user, own item, other team's item */
    private function twoTenants(): array
    {
        $teams = app(TeamManagementService::class);
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $teamA = $teams->createPersonalTeamForUser($userA);
        $teamB = $teams->createPersonalTeamForUser($userB);

        $mine = InventoryItem::factory()->create([
            'team_id' => $teamA->getKey(),
            'valuation_method' => 'average',
            'average_cost' => 5,
            'current_quantity' => 10, // value 50
        ]);
        $theirs = InventoryItem::factory()->create([
            'team_id' => $teamB->getKey(),
            'valuation_method' => 'average',
            'average_cost' => 5,
            'current_quantity' => 100, // value 500
        ]);

        return [$userA->fresh(), $mine, $theirs];
    }

    public function test_inventory_report_only_lists_the_acting_teams_items(): void
    {
        [$userA, $mine, $theirs] = $this->twoTenants();

        $this->actingAs($userA);
        $ids = app(InventoryService::class)->getInventoryReport()->pluck('id');

        $this->assertTrue($ids->contains($mine->getKey()), 'own item missing');
        $this->assertFalse($ids->contains($theirs->getKey()), 'LEAK: another tenant\'s inventory item exposed');
    }

    public function test_inventory_value_only_sums_the_acting_teams_items(): void
    {
        [$userA] = $this->twoTenants();

        $this->actingAs($userA);

        // Only team A's item (10 * 5 = 50), not team B's (100 * 5 = 500).
        $this->assertEquals(50.0, app(InventoryService::class)->getInventoryValue());
    }

    public function test_low_stock_matches_each_active_item_against_its_own_reorder_point(): void
    {
        [$user, $mine, $theirs] = $this->twoTenants();
        $mine->update(['reorder_point' => 10, 'is_active' => true]);
        $theirs->update(['reorder_point' => 200, 'is_active' => true]);
        $aboveThreshold = InventoryItem::factory()->create([
            'team_id' => $mine->team_id, 'current_quantity' => 11, 'reorder_point' => 10, 'is_active' => true,
        ]);
        $inactive = InventoryItem::factory()->create([
            'team_id' => $mine->team_id, 'current_quantity' => 0, 'reorder_point' => 10, 'is_active' => false,
        ]);

        $this->actingAs($user);
        $this->assertSame([$mine->getKey()], app(InventoryService::class)->checkLowStock()->modelKeys());
        $this->assertFalse($aboveThreshold->needsReorder());
        $this->assertTrue($inactive->needsReorder());
    }
}
