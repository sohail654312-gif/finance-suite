<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function legacyLineCandidate(): array
{
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $account = Account::factory()->create(['team_id' => $team->getKey()]);
    $journal = JournalEntry::factory()->create([
        'team_id' => $team->getKey(),
        'user_id' => $user->getKey(),
        'entry_date' => '2026-10-07',
    ]);
    $line = $journal->lines()->create([
        'account_id' => $account->getKey(),
        'debit_amount' => '100.00',
        'credit_amount' => '0.00',
    ]);

    return [$journal, $line, $account];
}

it('permits draft line preparation but rejects new lines on posted legacy journals', function (): void {
    [$journal, $line, $account] = legacyLineCandidate();
    expect($line->exists)->toBeTrue();

    $journal->forceFill(['is_posted' => true, 'posted_at' => now()])->save();

    expect(fn () => $journal->lines()->create([
        'account_id' => $account->getKey(),
        'debit_amount' => '0.00',
        'credit_amount' => '100.00',
    ]))->toThrow(DomainException::class, 'Posted journal entry lines are immutable.');

    expect($journal->lines()->count())->toBe(1);
});

it('rejects posted legacy line edits and deletes without changing its original values', function (): void {
    [$journal, $line] = legacyLineCandidate();
    $journal->forceFill(['is_posted' => true, 'posted_at' => now()])->save();

    expect(fn () => $line->update(['debit_amount' => '999.00']))
        ->toThrow(DomainException::class, 'Posted journal entry lines are immutable.');
    expect(fn () => $line->delete())
        ->toThrow(DomainException::class, 'Posted journal entry lines are immutable.');

    expect($line->fresh()->debit_amount)->toBe('100.00')
        ->and($journal->lines()->count())->toBe(1);
});

it('rejects reparenting a line from a posted journal into a draft journal', function (): void {
    [$posted, $line] = legacyLineCandidate();
    [$draft] = legacyLineCandidate();
    $posted->forceFill(['is_posted' => true, 'posted_at' => now()])->save();

    expect(fn () => $line->update(['journal_entry_id' => $draft->getKey()]))
        ->toThrow(DomainException::class, 'Posted journal entry lines are immutable.');

    expect((int) $line->fresh()->journal_entry_id)->toBe((int) $posted->getKey());
});

it('rejects reparenting a draft line into an existing posted journal', function (): void {
    [$draft, $line] = legacyLineCandidate();
    [$posted] = legacyLineCandidate();
    $posted->forceFill(['is_posted' => true, 'posted_at' => now()])->save();

    expect(fn () => $line->update(['journal_entry_id' => $posted->getKey()]))
        ->toThrow(DomainException::class, 'Posted journal entry lines are immutable.');

    expect((int) $line->fresh()->journal_entry_id)->toBe((int) $draft->getKey());
});
