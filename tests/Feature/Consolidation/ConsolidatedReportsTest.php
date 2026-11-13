<?php

declare(strict_types=1);

namespace Tests\Feature\Consolidation;

use App\Filament\App\Pages\ConsolidatedReports;
use App\Models\ConsolidationGroup;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConsolidatedReportsTest extends TestCase
{
    use RefreshDatabase;

    private function team(User $owner): Team
    {
        return Team::forceCreate(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => false]);
    }

    public function test_generate_populates_the_three_consolidated_statements(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $a = $this->team($user);
        $b = $this->team($user);

        $group = ConsolidationGroup::create(['name' => 'G', 'owner_team_id' => $a->id]);
        $group->members()->sync([$a->id, $b->id]);

        $page = new ConsolidatedReports();
        $page->data = ['consolidation_group_id' => $group->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'];
        $page->generate();

        $this->assertArrayHasKey('net_income', $page->profitAndLoss['consolidated']);
        $this->assertArrayHasKey('assets', $page->balanceSheet['consolidated']);
        $this->assertArrayHasKey('net_change_in_cash', $page->cashFlow['consolidated']);
    }

    public function test_group_visible_only_to_member_team_users(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $a = $this->team($owner);

        $group = ConsolidationGroup::create(['name' => 'G', 'owner_team_id' => $a->id]);
        $group->members()->sync([$a->id]);

        $this->actingAs($owner);
        $this->assertArrayHasKey($group->id, (new ConsolidatedReports())->visibleGroups());

        $this->actingAs($stranger);
        $this->assertArrayNotHasKey($group->id, (new ConsolidatedReports())->visibleGroups());
    }

    public function test_generate_ignores_a_group_the_user_cannot_see(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $a = $this->team($owner);
        $group = ConsolidationGroup::create(['name' => 'G', 'owner_team_id' => $a->id]);
        $group->members()->sync([$a->id]);

        $this->actingAs($stranger);
        $page = new ConsolidatedReports();
        $page->data = ['consolidation_group_id' => $group->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'];
        $page->generate();

        $this->assertNull($page->profitAndLoss);
    }

    public function test_reports_form_validates_dates_and_discards_previous_results_after_a_denied_selection(): void
    {
        $owner = User::factory()->create();
        $team = $this->team($owner);
        $group = ConsolidationGroup::create(['name' => 'Owned group', 'owner_team_id' => $team->id]);
        $group->members()->sync([$team->id]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);

        $page = Livewire::test(ConsolidatedReports::class)
            ->fillForm(['consolidation_group_id' => $group->id, 'start_date' => '2026-12-31', 'end_date' => '2026-01-01'])
            ->call('generate')->assertHasErrors(['data.end_date'])
            ->set('data.end_date', '2026-12-31')
            ->call('generate')->assertHasNoErrors();
        $this->assertArrayHasKey('consolidated', $page->get('profitAndLoss'));
        $page->set('data.consolidation_group_id', 99999)
            ->call('generate')
            ->assertSet('profitAndLoss', null)->assertSet('balanceSheet', null)->assertSet('cashFlow', null);
    }
}
