<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->teamId = app(TeamManagementService::class)
            ->createPersonalTeamForUser($this->user)->id;
        $this->user = $this->user->fresh();
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/journal-entries')->assertUnauthorized();
    }

    public function test_store_creates_balanced_entry_with_lines(): void
    {
        $cash = Account::factory()->create(['team_id' => $this->teamId, 'normal_balance' => 'debit']);
        $revenue = Account::factory()->create(['team_id' => $this->teamId, 'normal_balance' => 'credit']);

        $response = $this->actingAs($this->user)->postJson('/api/journal-entries', [
            'entry_date' => '2026-06-01',
            'entry_type' => 'general',
            'memo' => 'API entry',
            'lines' => [
                ['account_id' => $cash->id, 'debit_amount' => 100, 'credit_amount' => 0],
                ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 100],
            ],
        ]);

        $response->assertCreated();
        $entry = JournalEntry::where('team_id', $this->teamId)->firstOrFail();
        $this->assertSame(2, $entry->lines()->count());
        $this->assertTrue($entry->isBalanced());
    }

    public function test_store_rejects_unbalanced_entry(): void
    {
        $cash = Account::factory()->create(['team_id' => $this->teamId]);

        $this->actingAs($this->user)->postJson('/api/journal-entries', [
            'entry_date' => '2026-06-01',
            'lines' => [
                ['account_id' => $cash->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ],
        ])->assertStatus(422);
    }

    public function test_show_lists_own_entry(): void
    {
        $entry = JournalEntry::create(['entry_date' => now(), 'entry_type' => 'general', 'user_id' => $this->user->id, 'team_id' => $this->teamId]);

        $this->actingAs($this->user)->getJson("/api/journal-entries/{$entry->id}")->assertOk();
    }

    public function test_store_adds_decimal_lines_exactly_without_float_rounding(): void
    {
        $cash = Account::factory()->create(['team_id' => $this->teamId]);
        $income = Account::factory()->create(['team_id' => $this->teamId]);

        $this->actingAs($this->user)->postJson('/api/journal-entries', [
            'entry_date' => '2026-10-07',
            'lines' => [
                ['account_id' => $cash->id, 'debit_amount' => '0.10', 'credit_amount' => '0.00'],
                ['account_id' => $cash->id, 'debit_amount' => '0.20', 'credit_amount' => '0.00'],
                ['account_id' => $income->id, 'debit_amount' => '0.00', 'credit_amount' => '0.30'],
            ],
        ])->assertCreated();

        $journal = JournalEntry::where('team_id', $this->teamId)->firstOrFail();
        $this->assertTrue($journal->isBalanced());
        $this->assertSame(3, $journal->lines()->count());
    }

    public function test_store_rejects_fractional_cents_and_does_not_create_a_journal(): void
    {
        $cash = Account::factory()->create(['team_id' => $this->teamId]);
        $income = Account::factory()->create(['team_id' => $this->teamId]);

        $this->actingAs($this->user)->postJson('/api/journal-entries', [
            'entry_date' => '2026-10-07',
            'lines' => [
                ['account_id' => $cash->id, 'debit_amount' => '10.001', 'credit_amount' => '0'],
                ['account_id' => $income->id, 'debit_amount' => '0', 'credit_amount' => '10.001'],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_entry_lines', 0);
    }

    public function test_store_rejects_two_sided_or_zero_value_lines(): void
    {
        $cash = Account::factory()->create(['team_id' => $this->teamId]);
        $income = Account::factory()->create(['team_id' => $this->teamId]);

        foreach ([
            ['debit_amount' => '10.00', 'credit_amount' => '10.00'],
            ['debit_amount' => '0.00', 'credit_amount' => '0.00'],
        ] as $invalid) {
            $this->actingAs($this->user)->postJson('/api/journal-entries', [
                'entry_date' => '2026-10-07',
                'lines' => [
                    ['account_id' => $cash->id, ...$invalid],
                    ['account_id' => $income->id, 'debit_amount' => '0.00', 'credit_amount' => '10.00'],
                ],
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_store_denies_an_identity_without_a_selected_company(): void
    {
        $this->user->forceFill(['current_team_id' => null])->save();

        $this->actingAs($this->user->fresh())->postJson('/api/journal-entries', [
            'entry_date' => '2026-10-07',
            'lines' => [],
        ])->assertForbidden();

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_store_denies_another_company_account_reference(): void
    {
        $other = User::factory()->create();
        $foreignTeam = app(TeamManagementService::class)->createPersonalTeamForUser($other);
        $cash = Account::factory()->create(['team_id' => $this->teamId]);
        $foreignIncome = Account::factory()->create(['team_id' => $foreignTeam->id]);

        $this->actingAs($this->user)->postJson('/api/journal-entries', [
            'entry_date' => '2026-10-07',
            'lines' => [
                ['account_id' => $cash->id, 'debit_amount' => '50.00', 'credit_amount' => '0.00'],
                ['account_id' => $foreignIncome->id, 'debit_amount' => '0.00', 'credit_amount' => '50.00'],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('journal_entries', 0);
    }
}
