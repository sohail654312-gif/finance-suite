<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    expect(app()->environment())->toBe('testing')
        ->and(DB::getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('finance_suite_test');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

it('serializes subledger posting and uses committed source values after contention', function (string $operation): void {
    $user = User::factory()->create();
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Synthetic contention', 'personal_team' => false]);
    app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'invoice_date' => '2026-06-01', 'total_amount' => '100.02', 'payment_status' => 'pending']);
    $payment = Payment::create(['team_id' => $team->id, 'invoice_id' => $invoice->id, 'payment_date' => '2026-06-02', 'payment_amount' => '50.01']);
    $secondPayment = $operation === 'partial payments'
        ? Payment::create($payment->only(['team_id', 'invoice_id', 'payment_date', 'payment_amount']))
        : $payment;
    $worker = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql'
            || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'finance_suite_test') {
            exit(90);
        }
        Illuminate\Support\Facades\DB::statement("SET application_name = 'subledger_posting_race'");
        $source = $argv[1] === 'invoice' ? App\Models\Invoice::findOrFail((int) $argv[2]) : App\Models\Payment::findOrFail((int) $argv[2]);
        echo "ready\n";
        flush();
        if (trim((string) fgets(STDIN)) !== 'go') {
            exit(91);
        }
        $service = $argv[1] === 'invoice' ? app(App\Services\InvoicePostingService::class) : app(App\Services\PaymentPostingService::class);
        $entry = $service->post($source);
        echo 'journal='.$entry->id."\n";
        PHP;

    $processes = [];
    $inputs = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $id = $operation === 'invoice' ? $invoice->id : ($i === 0 ? $payment->getKey() : $secondPayment->getKey());
            $process = new Process([PHP_BINARY, '-r', $worker, $operation === 'invoice' ? 'invoice' : 'payment', (string) $id], base_path(), timeout: 40);
            $input = new InputStream();
            $process->setInput($input);
            $process->start();
            $processes[] = $process;
            $inputs[] = $input;
        }
        foreach ($processes as $process) {
            $deadline = microtime(true) + 15;
            while (! str_contains($process->getOutput(), "ready\n") && $process->isRunning() && microtime(true) < $deadline) {
                $process->checkTimeout();
                usleep(20_000);
            }
            expect($process->getOutput())->toContain("ready\n");
        }
        DB::beginTransaction();
        Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        if ($operation === 'invoice') {
            Invoice::whereKey($invoice->id)->update(['total_amount' => '75.03']);
        } elseif ($operation === 'payment') {
            Payment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            Payment::whereKey($payment->getKey())->update(['payment_amount' => '75.03']);
        }
        foreach ($inputs as $input) {
            $input->write("go\n");
            $input->close();
        }
        $deadline = microtime(true) + 10;
        do {
            foreach ($processes as $process) {
                $process->isRunning();
                $process->checkTimeout();
            }
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE application_name = 'subledger_posting_race' AND wait_event_type = 'Lock'");
            if ((int) $waiting->total === 2) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        expect((int) $waiting->total)->toBe(2);
        DB::commit();
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
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
    $partial = $operation === 'partial payments';
    expect(JournalEntry::count())->toBe($partial ? 2 : 1);
    $accountNumber = $operation === 'invoice' ? 1100 : 1000;
    expect(Account::where('team_id', $team->id)->where('account_number', $accountNumber)->sole()->balance)->toBe($partial ? '100.02' : '75.03');
    if ($operation !== 'invoice') {
        expect($invoice->fresh()->payment_status)->toBe($partial ? 'paid' : 'partial');
    }
})->with(['invoice', 'payment', 'partial payments']);
