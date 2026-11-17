<?php

declare(strict_types=1);

use App\Filament\App\Resources\BankStatements\Pages\CreateBankStatement;
use App\Filament\App\Resources\BankStatements\Pages\ListBankStatements;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\ReconciliationRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BankStatementImportService;
use App\Services\ReconciliationService;
use App\Services\TeamManagementService;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('lists only tenant statements and refuses to create one for another tenant account', function (): void {
    $teams = app(TeamManagementService::class);
    $owner = User::factory()->create();
    $team = $teams->createPersonalTeamForUser($owner);
    $foreignTeam = $teams->createPersonalTeamForUser(User::factory()->create());
    $account = Account::factory()->create(['team_id' => $team->getKey()]);
    $foreignAccount = Account::factory()->create(['team_id' => $foreignTeam->getKey()]);
    $statement = BankStatement::factory()->create(['team_id' => $team->getKey(), 'account_id' => $account->getKey(), 'statement_date' => '2026-10-01']);
    $foreignStatement = BankStatement::factory()->create(['team_id' => $foreignTeam->getKey(), 'account_id' => $foreignAccount->getKey()]);

    $this->actingAs($owner->fresh());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);
    Livewire::test(ListBankStatements::class)
        ->assertCanSeeTableRecords([$statement])
        ->assertCanNotSeeTableRecords([$foreignStatement])
        ->filterTable('date', ['from' => '2026-10-02', 'until' => '2026-10-31'])
        ->assertCanNotSeeTableRecords([$statement]);

    $form = ['statement_date' => '2026-11-01', 'total_credits' => '25.00', 'total_debits' => '5.00', 'ending_balance' => '20.00'];
    Livewire::test(CreateBankStatement::class)
        ->fillForm($form + ['account_id' => $foreignAccount->getKey()])
        ->call('create')->assertHasFormErrors(['account_id']);
    $this->assertDatabaseCount('bank_statements', 2);

    Livewire::test(CreateBankStatement::class)
        ->fillForm($form + ['account_id' => $account->getKey()])
        ->call('create')->assertHasNoFormErrors();
    $this->assertDatabaseHas('bank_statements', ['team_id' => $team->getKey(), 'account_id' => $account->getKey(), 'ending_balance' => '20.00']);
});

it('does not reconcile a foreign transaction even when it references the same account and statement', function (): void {
    $teams = app(TeamManagementService::class);
    $team = $teams->createPersonalTeamForUser(User::factory()->create());
    $foreignTeam = $teams->createPersonalTeamForUser(User::factory()->create());
    $account = Account::factory()->create(['team_id' => $team->getKey()]);
    $statement = BankStatement::factory()->create([
        'team_id' => $team->getKey(), 'account_id' => $account->getKey(),
        'statement_date' => '2026-11-01', 'total_credits' => '10.00', 'total_debits' => '0.00',
    ]);
    $fields = ['account_id' => $account->getKey(), 'bank_statement_id' => $statement->getKey(),
        'transaction_date' => '2026-11-02', 'amount' => '10.00', 'reconciled' => false];
    $mine = Transaction::factory()->create($fields + ['team_id' => $team->getKey()]);
    $foreign = Transaction::factory()->create($fields + ['team_id' => $foreignTeam->getKey()]);

    $result = app(ReconciliationService::class)->reconcileStatement($statement);
    expect($result['matched_transactions'])->toBe(1)
        ->and($result['reconciled'])->toBeTrue()
        ->and($mine->fresh()->reconciled)->toBeTrue()
        ->and($foreign->fresh()->reconciled)->toBeFalse();
});

it('previews reconciled matches without altering their account or reconciliation status', function (): void {
    $owner = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($owner);
    $bank = Account::factory()->create(['team_id' => $team->getKey()]);
    $category = Account::factory()->create(['team_id' => $team->getKey()]);
    $statement = BankStatement::factory()->create([
        'team_id' => $team->getKey(), 'account_id' => $bank->getKey(),
        'statement_date' => '2026-11-01', 'total_credits' => '10.00', 'total_debits' => '0.00',
    ]);
    $transaction = Transaction::factory()->create([
        'team_id' => $team->getKey(), 'account_id' => $bank->getKey(), 'bank_statement_id' => $statement->getKey(),
        'transaction_date' => '2026-11-02', 'description' => 'Supplier refund', 'amount' => '10.00',
        'debit_account_id' => null, 'reconciled' => false,
    ]);
    ReconciliationRule::create([
        'team_id' => $team->getKey(), 'name' => 'Refund', 'match_field' => 'description',
        'match_operator' => 'contains', 'match_value' => 'refund', 'action_account_id' => $category->getKey(),
        'priority' => 1, 'is_active' => true,
    ]);
    $this->actingAs($owner->fresh());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);

    $preview = TestAction::make('view_discrepancies')->table($statement);
    Livewire::test(ListBankStatements::class)
        ->assertActionVisible($preview)
        ->assertActionExists($preview, function (Action $action): bool {
            $content = $action->getModalContent();
            expect($content)->toBeInstanceOf(View::class);
            expect($content->getData()['result']['matched_transactions'])->toBe(1);

            return true;
        });
    expect($transaction->fresh()->reconciled)->toBeFalse()
        ->and($transaction->fresh()->debit_account_id)->toBeNull()
        ->and($statement->fresh()->reconciled)->toBeFalse();

    Livewire::test(ListBankStatements::class)
        ->callAction(TestAction::make('reconcile')->table($statement))
        ->assertHasNoActionErrors();
    expect($transaction->fresh()->reconciled)->toBeTrue()
        ->and($transaction->fresh()->debit_account_id)->toBe($category->getKey())
        ->and($statement->fresh()->reconciled)->toBeTrue();
});

it('retains statement ownership on an import without an authenticated session', function (): void {
    $team = app(TeamManagementService::class)->createPersonalTeamForUser(User::factory()->create());
    $account = Account::factory()->create(['team_id' => $team->getKey()]);
    $statement = BankStatement::factory()->create(['team_id' => $team->getKey(), 'account_id' => $account->getKey()]);
    $disk = Storage::fake('local');
    $disk->put('statement.csv', "Date,Description,Amount\n2026-11-02,Refund,10.00\n");

    $transaction = app(BankStatementImportService::class)->importFromCsv($disk->path('statement.csv'), $statement)->sole()->fresh();
    expect($transaction->team_id)->toBe($team->getKey())
        ->and($transaction->bank_statement_id)->toBe($statement->getKey())
        ->and($transaction->reconciled)->toBeFalse();
});
