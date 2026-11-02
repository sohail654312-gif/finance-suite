<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    // This suite deliberately uses committed rows and independent PHP/PDO
    // processes. Never permit its destructive reset outside the dedicated CI DB.
    expect(app()->environment())->toBe('testing')
        ->and(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('finance_suite_test');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

it('serializes competing legacy journal requests without duplicate financial effects', function (string $operation): void {
    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $cash = Account::factory()->create(['team_id' => $team->getKey(), 'normal_balance' => 'debit', 'balance' => '0.00']);
    $income = Account::factory()->create(['team_id' => $team->getKey(), 'normal_balance' => 'credit', 'balance' => '0.00']);
    $entry = JournalEntry::factory()->create([
        'team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'entry_date' => '2026-10-07',
    ]);
    $entry->lines()->create(['account_id' => $cash->getKey(), 'debit_amount' => '100.01', 'credit_amount' => '0.00']);
    $entry->lines()->create(['account_id' => $income->getKey(), 'debit_amount' => '0.00', 'credit_amount' => '100.01']);
    if ($operation === 'reverse') {
        $entry->post();
    }
    $secondEntry = null;
    if ($operation === 'parallel journals') {
        $secondEntry = JournalEntry::factory()->create([
            'team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'entry_date' => '2026-10-07',
        ]);
        foreach ($entry->lines as $line) {
            $secondEntry->lines()->create($line->only(['account_id', 'debit_amount', 'credit_amount']));
        }
    }
    $originalPostedAt = $entry->posted_at?->toDateTimeString();

    $worker = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql'
            || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'finance_suite_test') {
            exit(90);
        }
        Illuminate\Support\Facades\DB::statement("SET application_name = 'legacy_journal_race'");
        $entry = App\Models\JournalEntry::findOrFail((int) $argv[1]);
        echo "ready\n";
        flush();
        if (trim((string) fgets(STDIN)) !== 'go') {
            exit(91);
        }
        try {
            if ($argv[2] === 'edit') {
                Illuminate\Support\Facades\DB::transaction(function () use ($entry): void {
                    Illuminate\Support\Facades\DB::table('journal_entry_lines')->where('journal_entry_id', $entry->getKey())
                        ->where('debit_amount', '>', 0)->update(['debit_amount' => '50.00']);
                    Illuminate\Support\Facades\DB::table('journal_entry_lines')->where('journal_entry_id', $entry->getKey())
                        ->where('credit_amount', '>', 0)->update(['credit_amount' => '50.00']);
                });
            } else {
                $argv[2] === 'post' ? $entry->post() : $entry->reverse('2026-10-08');
            }
            echo "completed\n";
        } catch (DomainException $exception) {
            echo $exception->getMessage()."\n";
            exit(10);
        } catch (Illuminate\Database\QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'immutable')) {
                throw $exception;
            }
            echo "immutable\n";
            exit(20);
        }
        PHP;

    $processes = [];
    $inputs = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $entryId = $i === 1 && $secondEntry instanceof JournalEntry ? $secondEntry->getKey() : $entry->getKey();
            $action = $operation === 'reverse' ? 'reverse' : 'post';
            if ($operation === 'post versus SQL edit' && $i === 1) {
                $action = 'edit';
            }
            $process = new Process([PHP_BINARY, '-r', $worker, (string) $entryId, $action], base_path(), timeout: 30);
            $input = new InputStream();
            $process->setInput($input);
            $process->start();
            $processes[] = $process;
            $inputs[] = $input;
        }
        // Bootstrap both real applications before holding financial locks. Read
        // accumulated output: waitUntil only observes newly arriving chunks and
        // can miss a readiness message already captured while another child ran.
        foreach ($processes as $process) {
            $deadline = microtime(true) + 10;
            while (! str_contains($process->getOutput(), "ready\n") && $process->isRunning() && microtime(true) < $deadline) {
                $process->checkTimeout();
                usleep(20_000);
            }
            expect($process->getOutput())->toContain("ready\n");
        }
        DB::beginTransaction();
        if ($secondEntry instanceof JournalEntry) {
            Team::whereKey($team->getKey())->lockForUpdate()->firstOrFail();
        } else {
            JournalEntry::whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
        }
        foreach ($inputs as $input) {
            $input->write("go\n");
            $input->close();
        }

        // Prove both independent sessions actually contend for the held row;
        // starting two sequential requests is not concurrency evidence.
        $deadline = microtime(true) + 10;
        do {
            foreach ($processes as $process) {
                // Symfony pumps the stdin streams while updating process I/O.
                $process->isRunning();
                $process->checkTimeout();
            }
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE application_name = 'legacy_journal_race' AND wait_event_type = 'Lock'");
            if ((int) $waiting->total === 2) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        expect((int) $waiting->total)->toBe(2);
        DB::commit();
        foreach ($processes as $process) {
            $process->wait();
        }
        $statuses = array_map(fn (Process $process): ?int => $process->getExitCode(), $processes);
        sort($statuses);
        if ($operation === 'post versus SQL edit') {
            expect($processes[0]->getExitCode())->toBe(0)
                ->and($processes[1]->getExitCode())->toBeIn([0, 20]);
        } else {
            expect($statuses)->toBe($secondEntry instanceof JournalEntry ? [0, 0] : [0, 10]);
        }
        $combinedOutput = implode('', array_map(fn (Process $process): string => $process->getOutput(), $processes));
        if (! $secondEntry instanceof JournalEntry && $operation !== 'post versus SQL edit') {
            expect($combinedOutput)->toContain($operation === 'post' ? 'already posted' : 'already reversed');
        }
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }

    $balance = match ($operation) {
        'post' => '100.01',
        'reverse' => '0.00',
        'post versus SQL edit' => $processes[1]->getExitCode() === 0 ? '50.00' : '100.01',
        default => '200.02',
    };
    expect($cash->fresh()->balance)->toBe($balance)
        ->and($income->fresh()->balance)->toBe($balance)
        ->and($entry->fresh()->is_posted)->toBeTrue()
        ->and($entry->reversal()->count())->toBe($operation === 'reverse' ? 1 : 0);
    if ($secondEntry instanceof JournalEntry) {
        expect($secondEntry->fresh()->is_posted)->toBeTrue();
    }
    if ($operation === 'reverse') {
        expect($entry->fresh()->posted_at->toDateTimeString())->toBe($originalPostedAt);
    }
})->with(['post', 'reverse', 'parallel journals', 'post versus SQL edit']);
