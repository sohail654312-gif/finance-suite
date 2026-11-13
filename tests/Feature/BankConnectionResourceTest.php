<?php

namespace Tests\Feature;

use App\Filament\App\Resources\BankConnections\Pages\ListBankConnections;
use App\Filament\App\Resources\BankConnections\Pages\ViewBankConnection;
use App\Models\BankConnection;
use App\Models\User;
use App\Services\TeamManagementService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class BankConnectionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_bank_connection_can_be_created(): void
    {
        $connection = BankConnection::create([
            'user_id' => $this->user->id,
            'bank_id' => 'test_bank_001',
            'institution_name' => 'Test Bank',
            'credentials' => ['username' => 'test'],
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('bank_connections', [
            'id' => $connection->id,
            'bank_id' => 'test_bank_001',
            'institution_name' => 'Test Bank',
            'status' => 'active',
        ]);
    }

    public function test_bank_connection_has_user_relationship(): void
    {
        $connection = BankConnection::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $this->assertInstanceOf(User::class, $connection->user);
        $this->assertEquals($this->user->id, $connection->user_id);
    }

    public function test_bank_connection_credentials_are_encrypted(): void
    {
        $connection = BankConnection::factory()->create([
            'user_id' => $this->user->id,
            'credentials' => ['password' => 'secret123'],
        ]);

        // Check that credentials are encrypted in the database
        $rawConnection = \DB::table('bank_connections')->find($connection->id);
        $this->assertNotEquals(['password' => 'secret123'], $rawConnection->credentials);

        // Check that credentials are decrypted when accessed through the model
        $this->assertEquals(['password' => 'secret123'], $connection->credentials);
    }

    public function test_bank_connection_plaid_access_token_is_encrypted(): void
    {
        $connection = BankConnection::factory()->create([
            'user_id' => $this->user->id,
            'plaid_access_token' => 'access-sandbox-token-123',
        ]);

        // Check that token is encrypted in the database
        $rawConnection = \DB::table('bank_connections')->find($connection->id);
        $this->assertNotEquals('access-sandbox-token-123', $rawConnection->plaid_access_token);

        // Check that token is decrypted when accessed through the model
        $this->assertEquals('access-sandbox-token-123', $connection->plaid_access_token);
    }

    public function test_bank_connection_has_transactions_relationship(): void
    {
        $connection = BankConnection::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $this->assertInstanceOf(Collection::class, $connection->transactions);
    }

    public function test_bank_connection_can_track_plaid_sync_status(): void
    {
        $connection = BankConnection::factory()->create([
            'user_id' => $this->user->id,
            'plaid_item_id' => 'item-test-123',
            'plaid_cursor' => null,
            'last_synced_at' => null,
        ]);

        $this->assertNull($connection->plaid_cursor);
        $this->assertNull($connection->last_synced_at);

        // Simulate a sync
        $connection->update([
            'plaid_cursor' => 'cursor-abc-123',
            'last_synced_at' => now(),
        ]);

        $this->assertNotNull($connection->plaid_cursor);
        $this->assertNotNull($connection->last_synced_at);
    }

    public function test_bank_connection_supports_different_statuses(): void
    {
        $statuses = ['active', 'inactive', 'error', 'pending'];

        foreach ($statuses as $status) {
            $connection = BankConnection::factory()->create([
                'user_id' => $this->user->id,
                'status' => $status,
            ]);

            $this->assertEquals($status, $connection->status);
        }
    }

    public function test_bank_connection_can_store_plaid_metadata(): void
    {
        $connection = BankConnection::factory()->create([
            'user_id' => $this->user->id,
            'plaid_access_token' => 'access-sandbox-token',
            'plaid_item_id' => 'item-sandbox-123',
            'plaid_institution_id' => 'ins_109508',
            'institution_name' => 'Chase Bank',
        ]);

        $this->assertEquals('item-sandbox-123', $connection->plaid_item_id);
        $this->assertEquals('ins_109508', $connection->plaid_institution_id);
        $this->assertEquals('Chase Bank', $connection->institution_name);
    }

    public function test_bank_connection_can_be_disconnected(): void
    {
        $connection = BankConnection::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'plaid_access_token' => 'access-token',
            'plaid_item_id' => 'item-123',
            'plaid_institution_id' => 'ins-123',
        ]);

        // Simulate disconnection
        $connection->update([
            'status' => 'inactive',
            'plaid_access_token' => null,
            'plaid_item_id' => null,
            'plaid_institution_id' => null,
        ]);

        $connection->refresh();

        $this->assertEquals('inactive', $connection->status);
        $this->assertNull($connection->plaid_access_token);
        $this->assertNull($connection->plaid_item_id);
        $this->assertNull($connection->plaid_institution_id);
    }

    public function test_sync_actions_respect_connection_status_and_keep_other_tenants_off_the_table(): void
    {
        $teams = app(TeamManagementService::class);
        $team = $teams->createPersonalTeamForUser($this->user);
        $foreignOwner = User::factory()->create();
        $foreignTeam = $teams->createPersonalTeamForUser($foreignOwner);
        $active = BankConnection::factory()->create([
            'bank_id' => 'plaid', 'user_id' => $this->user->id, 'team_id' => $team->id, 'status' => 'active',
        ]);
        $inactive = BankConnection::factory()->create([
            'bank_id' => 'plaid', 'user_id' => $this->user->id, 'team_id' => $team->id, 'status' => 'inactive',
        ]);
        $foreign = BankConnection::factory()->create(['user_id' => $foreignOwner->id, 'team_id' => $foreignTeam->id]);
        $this->actingAs($this->user->fresh());
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);
        Http::preventStrayRequests();

        Livewire::test(ListBankConnections::class)
            ->assertCanSeeTableRecords([$active, $inactive])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertActionVisible(TestAction::make('sync_transactions')->table($active))
            ->assertActionHidden(TestAction::make('sync_transactions')->table($inactive));
        Livewire::test(ViewBankConnection::class, ['record' => $inactive->getRouteKey()])
            ->assertActionHidden('sync_transactions');
        Http::assertNothingSent();
    }

    public function test_view_action_advances_the_cursor_only_on_a_successful_provider_sync(): void
    {
        $team = app(TeamManagementService::class)->createPersonalTeamForUser($this->user);
        $connection = BankConnection::factory()->create([
            'bank_id' => 'plaid', 'user_id' => $this->user->id, 'team_id' => $team->id, 'status' => 'active',
        ]);
        $this->actingAs($this->user->fresh());
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);
        Http::preventStrayRequests();
        Http::fake(['sandbox.plaid.com/transactions/sync' => Http::sequence()
            ->push(['added' => [], 'modified' => [], 'removed' => [], 'next_cursor' => 'saved-cursor', 'has_more' => false])
            ->push(['error_code' => 'INVALID_REQUEST'], 400)]);

        $view = Livewire::test(ViewBankConnection::class, ['record' => $connection->getRouteKey()]);
        $view->callAction('sync_transactions')->assertHasNoActionErrors();
        $this->assertSame('saved-cursor', $connection->fresh()->plaid_cursor);
        $view->callAction('sync_transactions')->assertHasNoActionErrors();
        $this->assertSame('saved-cursor', $connection->fresh()->plaid_cursor);
        $this->assertDatabaseCount('transactions', 0);
        Http::assertSentCount(2);
    }
}
