<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{JournalEntry, Account, Account, Team} */
function legacyHistoryFixture(): array
{
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $cash = Account::factory()->create(['team_id' => $team->getKey(), 'normal_balance' => 'debit', 'balance' => '0.00']);
    $income = Account::factory()->create(['team_id' => $team->getKey(), 'normal_balance' => 'credit', 'balance' => '0.00']);
    $entry = JournalEntry::factory()->create([
        'team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'entry_date' => '2026-10-07',
    ]);
    $entry->lines()->create(['account_id' => $cash->getKey(), 'debit_amount' => '0.10', 'credit_amount' => '0.00']);
    $entry->lines()->create(['account_id' => $income->getKey(), 'debit_amount' => '0.00', 'credit_amount' => '0.30']);
    $entry->lines()->create(['account_id' => $cash->getKey(), 'debit_amount' => '0.20', 'credit_amount' => '0.00']);

    return [$entry, $cash, $income, $team];
}

it('posts persisted lines with exact cents and accumulates repeated account lines', function (): void {
    [$entry, $cash, $income] = legacyHistoryFixture();
    $entry->load('lines.account');
    $entry->post();

    expect($cash->fresh()->balance)->toBe('0.30')
        ->and($income->fresh()->balance)->toBe('0.30')
        ->and($entry->is_posted)->toBeTrue();
});

it('rejects a second posting from a stale model without applying duplicate balances', function (): void {
    [$entry, $cash, $income] = legacyHistoryFixture();
    $stale = $entry->fresh();
    $entry->post();

    expect(fn () => $stale->post())->toThrow(DomainException::class, 'already posted');
    expect($cash->fresh()->balance)->toBe('0.30')->and($income->fresh()->balance)->toBe('0.30');
});

it('ignores stale loaded line and account relations when computing the posting', function (): void {
    [$entry, $cash, $income] = legacyHistoryFixture();
    $entry->load('lines.account');
    $entry->lines()->where('debit_amount', '0.20')->firstOrFail()->update(['debit_amount' => '0.30']);
    $entry->lines()->where('credit_amount', '0.30')->firstOrFail()->update(['credit_amount' => '0.40']);
    $entry->post();

    expect($cash->fresh()->balance)->toBe('0.40')->and($income->fresh()->balance)->toBe('0.40');
});

it('rejects posting a journal without an authoritative team', function (): void {
    [$entry, $cash, $income] = legacyHistoryFixture();
    $entry->team_id = null;
    $entry->save();

    expect(fn () => $entry->post())->toThrow(DomainException::class, 'must belong to a posting team');
    expect($cash->fresh()->balance)->toBe('0.00')->and($income->fresh()->balance)->toBe('0.00');
});

it('rolls back every balance when a period closes before posting', function (): void {
    [$entry, $cash, $income, $team] = legacyHistoryFixture();
    $entry->load('team');
    $team->forceFill(['books_locked_before' => '2026-10-08'])->save();

    expect(fn () => $entry->post())->toThrow(DomainException::class, 'books are closed');
    expect($cash->fresh()->balance)->toBe('0.00')
        ->and($income->fresh()->balance)->toBe('0.00')
        ->and($entry->fresh()->is_posted)->toBeFalse();
});

it('preserves the original posting and records a balanced traceable compensating entry', function (): void {
    [$entry, $cash, $income] = legacyHistoryFixture();
    $entry->post();
    $original = $entry->getRawOriginal();
    $originalLines = $entry->lines()->orderBy('id')->get()->map->getRawOriginal()->all();

    $entry->reverse('2026-10-08');
    $reversal = $entry->reversal()->firstOrFail();

    expect($entry->getRawOriginal())->toBe($original)
        ->and($entry->lines()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($originalLines)
        ->and($reversal->is_posted)->toBeTrue()
        ->and($reversal->isBalanced())->toBeTrue()
        ->and($reversal->entry_type)->toBe('reversing')
        ->and($reversal->team_id)->toBe($entry->team_id)
        ->and($reversal->reference_number)->toBe($entry->entry_number)
        ->and($cash->fresh()->balance)->toBe('0.00')
        ->and($income->fresh()->balance)->toBe('0.00')
        ->and(JournalEntry::where('is_posted', true)->count())->toBe(2);

    $debits = '0.00';
    $credits = '0.00';
    foreach ([$entry, $reversal] as $journal) {
        foreach ($journal->lines as $line) {
            $debits = bcadd($debits, $line->debit_amount, 2);
            $credits = bcadd($credits, $line->credit_amount, 2);
        }
    }
    expect($debits)->toBe('0.60')->and($credits)->toBe('0.60');
});

it('rejects a duplicate reversal even when called through a stale instance', function (): void {
    [$entry, $cash, $income] = legacyHistoryFixture();
    $entry->post();
    $stale = $entry->fresh();
    $entry->reverse('2026-10-08');

    expect(fn () => $stale->reverse('2026-10-08'))->toThrow(DomainException::class, 'already reversed');
    expect($entry->reversal()->count())->toBe(1)
        ->and($cash->fresh()->balance)->toBe('0.00')->and($income->fresh()->balance)->toBe('0.00');
});

it('can reverse closed original history into an open period without rewriting that history', function (): void {
    [$entry, $cash, $income, $team] = legacyHistoryFixture();
    $entry->post();
    $team->forceFill(['books_locked_before' => '2026-10-08'])->save();
    $entry->reverse('2026-10-08');

    expect($entry->is_posted)->toBeTrue()
        ->and($entry->reversal()->firstOrFail()->entry_date->toDateString())->toBe('2026-10-08')
        ->and($cash->fresh()->balance)->toBe('0.00');
});

it('rolls back a reversal targeting a closed period without leaving a link or balance change', function (): void {
    [$entry, $cash, $income, $team] = legacyHistoryFixture();
    $entry->post();
    $team->forceFill(['books_locked_before' => '2026-10-09'])->save();

    expect(fn () => $entry->reverse('2026-10-08'))->toThrow(DomainException::class, 'books are closed');
    expect($entry->reversal()->exists())->toBeFalse()
        ->and($entry->fresh()->is_posted)->toBeTrue()
        ->and($cash->fresh()->balance)->toBe('0.30')->and($income->fresh()->balance)->toBe('0.30');
});

it('rejects invalid or backdated reversal dates', function (string $date): void {
    [$entry, $cash] = legacyHistoryFixture();
    $entry->post();

    expect(fn () => $entry->reverse($date))->toThrow(DomainException::class, 'Reversal date');
    expect($entry->reversal()->exists())->toBeFalse()->and($cash->fresh()->balance)->toBe('0.30');
})->with(['2026-10-06', '2026-02-30', '2026-13-07', '2026-10-08 12:00:00']);

it('rejects normal posted parent mutations and deletion using persisted state', function (): void {
    [$entry] = legacyHistoryFixture();
    $stale = $entry->fresh();
    $entry->post();

    expect(fn () => $stale->update(['memo' => 'tampered']))->toThrow(DomainException::class, 'immutable');
    expect(fn () => $stale->delete())->toThrow(DomainException::class, 'immutable');
    expect($entry->fresh()->is_posted)->toBeTrue();
});

it('blocks SQL and quiet-write bypasses against posted history', function (string $operation): void {
    [$entry] = legacyHistoryFixture();
    $line = $entry->lines()->firstOrFail();
    $entry->post();
    $originalMemo = $entry->memo;

    expect(fn () => DB::transaction(function () use ($operation, $entry, $line): void {
        match ($operation) {
            'parent update' => DB::table('journal_entries')->where('id', $entry->getKey())->update(['is_posted' => false]),
            'parent delete' => DB::table('journal_entries')->where('id', $entry->getKey())->delete(),
            'quiet parent' => $entry->forceFill(['memo' => 'tampered'])->saveQuietly(),
            'line update' => DB::table('journal_entry_lines')->where('id', $line->getKey())->update(['debit_amount' => '999.00']),
            'line delete' => DB::table('journal_entry_lines')->where('id', $line->getKey())->delete(),
            'line insert' => DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $entry->getKey(), 'account_id' => $line->account_id,
                'debit_amount' => '999.00', 'credit_amount' => '0.00',
            ]),
        };
    }))->toThrow(QueryException::class, 'immutable');

    expect($entry->fresh()->is_posted)->toBeTrue()
        ->and($entry->fresh()->memo)->toBe($originalMemo)
        ->and($entry->lines()->count())->toBe(3)
        ->and($line->fresh()->debit_amount)->toBe('0.10');
})->with(['parent update', 'parent delete', 'quiet parent', 'line update', 'line delete', 'line insert']);

it('preserves permitted bulk draft edits and rejects SQL reparenting into posted history', function (): void {
    [$posted] = legacyHistoryFixture();
    [$draft] = legacyHistoryFixture();
    $line = $draft->lines()->firstOrFail();
    DB::table('journal_entry_lines')->where('id', $line->getKey())->update(['description' => 'draft change']);
    DB::table('journal_entries')->where('id', $draft->getKey())->update(['memo' => 'draft change']);
    $posted->post();

    expect(fn () => DB::transaction(fn () => DB::table('journal_entry_lines')->where('id', $line->getKey())
        ->update(['journal_entry_id' => $posted->getKey()])))->toThrow(QueryException::class, 'immutable');
    expect($draft->fresh()->memo)->toBe('draft change')
        ->and($line->fresh()->description)->toBe('draft change')
        ->and($line->fresh()->journal_entry_id)->toBe($draft->getKey());
});
