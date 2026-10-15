<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function legacyBalanceCandidate(): array
{
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $journal = JournalEntry::factory()->create([
        'team_id' => $team->getKey(),
        'user_id' => $user->getKey(),
        'entry_date' => '2026-10-07',
    ]);

    return [$journal, $team];
}

it('rejects an empty or single-sided legacy journal before posting', function (): void {
    [$journal, $team] = legacyBalanceCandidate();

    expect($journal->isBalanced())->toBeFalse();
    expect(fn () => $journal->post())->toThrow(Exception::class, 'must be balanced');

    $cash = Account::factory()->create(['team_id' => $team->getKey(), 'normal_balance' => 'debit']);
    $income = Account::factory()->create(['team_id' => $team->getKey(), 'normal_balance' => 'credit']);
    $journal->lines()->create([
        'account_id' => $cash->getKey(),
        'debit_amount' => '100.00',
        'credit_amount' => '0.00',
    ]);

    expect($journal->isBalanced())->toBeFalse();
    expect(fn () => $journal->post())->toThrow(Exception::class, 'must be balanced');

    $journal->lines()->create([
        'account_id' => $income->getKey(),
        'debit_amount' => '0.00',
        'credit_amount' => '100.00',
    ]);

    expect($journal->isBalanced())->toBeTrue();
    expect($journal->post()->is_posted)->toBeTrue();
});

it('rejects zero-value and dual-sided legacy journal lines', function (): void {
    [$journal, $team] = legacyBalanceCandidate();
    $account = Account::factory()->create(['team_id' => $team->getKey()]);

    $first = $journal->lines()->create([
        'account_id' => $account->getKey(),
        'debit_amount' => '0.00',
        'credit_amount' => '0.00',
    ]);
    $journal->lines()->create([
        'account_id' => $account->getKey(),
        'debit_amount' => '0.00',
        'credit_amount' => '0.00',
    ]);

    expect($journal->isBalanced())->toBeFalse();
    expect(fn () => $journal->post())->toThrow(Exception::class, 'must be balanced');

    $first->update(['debit_amount' => '50.00', 'credit_amount' => '50.00']);

    expect($journal->isBalanced())->toBeFalse();
    expect($journal->is_posted)->toBeFalse();
});

it('refuses a balanced legacy journal whose credit account belongs to another company', function (): void {
    [$journal, $team] = legacyBalanceCandidate();
    $stranger = User::factory()->create();
    $otherTeam = app(TeamManagementService::class)->createPersonalTeamForUser($stranger);
    $own = Account::factory()->create([
        'team_id' => $team->getKey(),
        'normal_balance' => 'debit',
        'balance' => '0.00',
    ]);
    $foreign = Account::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'normal_balance' => 'credit',
        'balance' => '0.00',
    ]);

    $journal->lines()->create([
        'account_id' => $own->getKey(),
        'debit_amount' => '100.00',
        'credit_amount' => '0.00',
    ]);
    $journal->lines()->create([
        'account_id' => $foreign->getKey(),
        'debit_amount' => '0.00',
        'credit_amount' => '100.00',
    ]);

    expect($journal->isBalanced())->toBeTrue();
    expect(fn () => $journal->post())
        ->toThrow(DomainException::class, 'Journal accounts must belong to the posting team.');

    expect($journal->refresh()->is_posted)->toBeFalse()
        ->and($own->fresh()->balance)->toBe('0.00')
        ->and($foreign->fresh()->balance)->toBe('0.00');
});
