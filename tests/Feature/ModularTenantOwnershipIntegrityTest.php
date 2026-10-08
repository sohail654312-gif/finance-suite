<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\GeneralLedger\Models\JournalEntry;

uses(RefreshDatabase::class);

function modularOwnedAccountingUser(): User
{
    $user = User::factory()->create();
    app(TeamManagementService::class)->createPersonalTeamForUser($user);

    return $user->refresh();
}

it('stamps modular accounting ownership from the authenticated current team and denies another team', function (): void {
    $owner = modularOwnedAccountingUser();
    $stranger = modularOwnedAccountingUser();

    Sanctum::actingAs($owner, [
        'accounting.core.read',
        'accounting.core.write',
        'accounting.chart.read',
        'accounting.chart.write',
        'accounting.general-ledger.read',
        'accounting.general-ledger.write',
    ]);

    $entityId = $this->postJson('/api/v1/accounting/accounting-core/legal-entities', [
        'name' => 'Owned Synthetic Entity',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
    ])->assertCreated()->json('data.id');

    $entity = LegalEntity::query()->findOrFail($entityId);
    expect((int) $entity->team_id)->toBe((int) $owner->current_team_id);

    $bookId = $this->postJson('/api/v1/accounting/accounting-core/books', [
        'legal_entity_id' => $entity->id,
        'name' => 'Owned Book',
        'code' => 'OWNED',
        'accounting_basis' => 'accrual',
    ])->assertCreated()->json('data.id');

    $book = Book::query()->findOrFail($bookId);
    expect((int) $book->team_id)->toBe((int) $owner->current_team_id);

    $cashId = $this->postJson('/api/v1/accounting/chart-of-accounts/accounts', [
        'legal_entity_id' => $entity->id,
        'code' => '1000',
        'name' => 'Cash',
        'type' => 'asset',
    ])->assertCreated()->json('data.id');

    $revenueId = $this->postJson('/api/v1/accounting/chart-of-accounts/accounts', [
        'legal_entity_id' => $entity->id,
        'code' => '4000',
        'name' => 'Revenue',
        'type' => 'revenue',
    ])->assertCreated()->json('data.id');

    expect((int) Account::query()->findOrFail($cashId)->team_id)->toBe((int) $owner->current_team_id);

    $journalId = $this->postJson('/api/v1/accounting/general-ledger', [
        'book_id' => $book->id,
        'entry_date' => '2026-10-08',
        'lines' => [
            ['account_id' => $cashId, 'debit' => '100.00', 'credit' => '0.00'],
            ['account_id' => $revenueId, 'debit' => '0.00', 'credit' => '100.00'],
        ],
    ])->assertCreated()->json('data.id');

    expect((int) JournalEntry::query()->findOrFail($journalId)->team_id)->toBe((int) $owner->current_team_id);

    Sanctum::actingAs($stranger, [
        'accounting.core.read',
        'accounting.core.write',
        'accounting.chart.read',
        'accounting.chart.write',
        'accounting.general-ledger.read',
        'accounting.general-ledger.write',
    ]);

    $this->getJson('/api/v1/accounting/accounting-core/legal-entities')
        ->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/accounting/accounting-core/legal-entities/{$entity->id}")
        ->assertForbidden();
    $this->patchJson("/api/v1/accounting/accounting-core/legal-entities/{$entity->id}", ['name' => 'Stolen'])
        ->assertForbidden();

    $this->postJson('/api/v1/accounting/accounting-core/books', [
        'legal_entity_id' => $entity->id,
        'name' => 'Foreign Book',
        'code' => 'BAD',
        'accounting_basis' => 'accrual',
    ])->assertNotFound();

    $this->getJson('/api/v1/accounting/chart-of-accounts/accounts?legal_entity_id='.$entity->id)
        ->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/accounting/chart-of-accounts/accounts/tree?legal_entity_id='.$entity->id)
        ->assertNotFound();
    $this->postJson('/api/v1/accounting/chart-of-accounts/accounts', [
        'legal_entity_id' => $entity->id,
        'code' => '9999',
        'name' => 'Foreign',
        'type' => 'asset',
    ])->assertNotFound();

    $this->getJson('/api/v1/accounting/general-ledger?book_id='.$book->id)
        ->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/accounting/general-ledger/{$journalId}")
        ->assertForbidden();
    $this->getJson('/api/v1/accounting/general-ledger/balances?book_id='.$book->id)
        ->assertNotFound();
    $this->postJson('/api/v1/accounting/general-ledger', [
        'book_id' => $book->id,
        'entry_date' => '2026-10-08',
        'lines' => [
            ['account_id' => $cashId, 'debit' => '1.00', 'credit' => '0.00'],
            ['account_id' => $revenueId, 'debit' => '0.00', 'credit' => '1.00'],
        ],
    ])->assertNotFound();
});

it('fails closed when an API user has no selected owned team', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['accounting.core.read', 'accounting.core.write']);

    $this->getJson('/api/v1/accounting/accounting-core/legal-entities')->assertForbidden();
    $this->postJson('/api/v1/accounting/accounting-core/legal-entities', [
        'name' => 'Unowned Entity',
        'currency_code' => 'GBP',
    ])->assertForbidden();

    expect(LegalEntity::query()->count())->toBe(0);
});
