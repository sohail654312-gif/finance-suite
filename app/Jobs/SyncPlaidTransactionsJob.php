<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BankConnection;
use App\Models\BankFeedTransaction;
use App\Models\Currency;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlaidService;
use DateTimeImmutable;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

class SyncPlaidTransactionsJob implements ShouldQueue
{
    /** @var array{added:int,modified:int,removed:int,total_processed:int} */
    public array $summary = ['added' => 0, 'modified' => 0, 'removed' => 0, 'total_processed' => 0];

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $connectionId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PlaidService $plaidService): void
    {
        $this->summary = ['added' => 0, 'modified' => 0, 'removed' => 0, 'total_processed' => 0];
        try {
            $connection = BankConnection::findOrFail($this->connectionId);
            $this->assertConnectionAuthority($connection);
            if (config('finance-suite.demo')) {
                throw new LogicException('Plaid synchronization is disabled in demo mode.');
            }
            if (! is_string($connection->plaid_access_token) || $connection->plaid_access_token === '') {
                throw new LogicException('An authorized Plaid connection is required.');
            }

            // Check if connection is active
            if ($connection->status !== 'active') {
                Log::warning('Skipping sync for inactive connection', [
                    'connection_id' => $connection->id,
                    'status' => $connection->status,
                ]);

                return;
            }

            Log::info('Starting Plaid transaction sync', [
                'connection_id' => $connection->id,
            ]);

            DB::beginTransaction();
            try {
                $connection = BankConnection::whereKey($connection->getKey())->lockForUpdate()->firstOrFail();
                $this->assertConnectionAuthority($connection);
                if ($connection->status !== 'active') {
                    throw new LogicException('Plaid connection must remain active.');
                }
                $page = 0;
                do {
                    $previousCursor = $connection->plaid_cursor;
                    // Sync transactions from Plaid
                    try {
                        $transactionData = $plaidService->syncTransactions($connection);
                    } catch (Throwable $error) {
                        throw new RuntimeException(str_contains($error->getMessage(), 'User action required')
                            ? 'Plaid connection requires reauthentication.' : 'Plaid provider request failed.');
                    }

                    $added = $transactionData['added'] ?? [];
                    $modified = $transactionData['modified'] ?? [];
                    $removed = $transactionData['removed'] ?? [];
                    foreach ([$added, $modified, $removed] as $rows) {
                        if (! is_array($rows) || ! array_is_list($rows)) {
                            throw new InvalidArgumentException('Plaid transaction groups must be lists.');
                        }
                    }

                    $processedCount = 0;

                    // Process added transactions
                    foreach ($added as $plaidTransaction) {
                        $this->processPlaidTransaction($plaidTransaction, $connection);
                        $processedCount++;
                    }

                    // Process modified transactions
                    foreach ($modified as $plaidTransaction) {
                        $this->processPlaidTransaction($plaidTransaction, $connection, true);
                        $processedCount++;
                    }

                    // Process removed transactions (mark as deleted)
                    foreach ($removed as $removedTransaction) {
                        if (! is_array($removedTransaction) || ! is_string($removedTransaction['transaction_id'] ?? null)) {
                            throw new InvalidArgumentException('Plaid removal identity is invalid.');
                        }
                        $this->removeTransaction($removedTransaction['transaction_id'], $connection);
                    }
                    $this->summary['added'] += count($added);
                    $this->summary['modified'] += count($modified);
                    $this->summary['removed'] += count($removed);
                    $this->summary['total_processed'] += $processedCount;
                    $hasMore = $transactionData['has_more'] ?? false;
                    if (! is_bool($hasMore) || (++$page >= 100 && $hasMore)
                        || ($hasMore && (! is_string($connection->plaid_cursor) || $connection->plaid_cursor === $previousCursor))) {
                        throw new InvalidArgumentException('Plaid pagination must advance a bounded cursor.');
                    }
                } while ($hasMore);

                DB::commit();

                Log::info('Plaid transaction sync completed', [
                    'connection_id' => $connection->id,
                    'added' => count($added),
                    'modified' => count($modified),
                    'removed' => count($removed),
                    'total_processed' => $processedCount,
                ]);
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            Log::error('Plaid transaction sync job failed', [
                'connection_id' => $this->connectionId,
                'exception_type' => $e::class,
            ]);

            // Check if we should mark connection as needing re-auth
            if ($e->getMessage() === 'Plaid connection requires reauthentication.') {
                try {
                    $connection = BankConnection::find($this->connectionId);
                    if ($connection) {
                        $connection->update(['status' => 'requires_reauth']);
                    }
                } catch (Exception $updateError) {
                    Log::error('Failed to update connection status', [
                        'connection_id' => $this->connectionId,
                        'exception_type' => $updateError::class,
                    ]);
                }
            }

            throw $e;
        }
    }

    /**
     * Process a Plaid transaction and create/update in database
     */
    protected function processPlaidTransaction(array $plaidTransaction, BankConnection $connection, bool $isUpdate = false): void
    {
        $currencyCode = $plaidTransaction['iso_currency_code'] ?? null;
        $currency = is_string($currencyCode) && preg_match('/^[A-Z]{3}$/D', $currencyCode)
            ? Currency::where('code', $currencyCode)->first() : null;
        if ($currency === null || ! empty($plaidTransaction['unofficial_currency_code'])) {
            throw new InvalidArgumentException('Plaid transaction currency must be configured before import.');
        }
        $transactionId = $plaidTransaction['transaction_id'] ?? null;
        $date = $plaidTransaction['date'] ?? $plaidTransaction['authorized_date'] ?? null;
        $amount = $plaidTransaction['amount'] ?? null;
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (! is_string($transactionId) || $transactionId === '' || strlen($transactionId) > 255
            || $parsed === false || $parsed->format('Y-m-d') !== $date
            || (! is_string($amount) && ! is_int($amount) && ! is_float($amount))
            || preg_match('/^-?\d{1,10}(?:\.\d{1,2})?$/D', (string) $amount) !== 1
            || ! is_bool($plaidTransaction['pending'] ?? null)) {
            throw new InvalidArgumentException('Plaid identity, date, exact cents and pending state must be valid.');
        }
        $existing = Transaction::where('external_id', $transactionId)->lockForUpdate()->first();
        if ($existing !== null && ((int) $existing->team_id !== (int) $connection->team_id
            || (int) $existing->bank_connection_id !== (int) $connection->id)) {
            throw new LogicException('Plaid transaction belongs to another company or connection.');
        }
        $values = [
            'currency_id' => $currency->getKey(),
            'team_id' => $connection->team_id,
            'transaction_date' => $date,
            'amount' => bcadd(ltrim((string) $amount, '-'), '0', 2),
            'type' => bccomp((string) $amount, '0', 2) > 0 ? 'debit' : 'credit',
            'description' => $plaidTransaction['name'] ?? 'Unknown',
            'category' => $this->categorizePlaidTransaction($plaidTransaction),
            'status' => $plaidTransaction['pending'] ? 'pending' : 'posted',
        ];
        if ($existing !== null && $existing->reconciled) {
            $existing->fill($values);
            if ($existing->isDirty()) {
                throw new LogicException('Reconciled Plaid transactions require review before correction.');
            }
        }

        // Find or create the transaction
        $transaction = Transaction::updateOrCreate(
            [
                'external_id' => $transactionId,
                'bank_connection_id' => $connection->id,
            ],
            $values
        );

        // Store raw Plaid data
        BankFeedTransaction::updateOrCreate(
            [
                'transaction_id' => $transaction->getKey(),
                'bank_connection_id' => $connection->id,
            ],
            [
                'raw_data' => $plaidTransaction,
            ]
        );
    }

    /**
     * Remove a transaction that was deleted in Plaid
     */
    protected function removeTransaction(string $externalId, BankConnection $connection): void
    {
        $transaction = Transaction::where('external_id', $externalId)
            ->where('bank_connection_id', $connection->id)
            ->where('team_id', $connection->team_id)->lockForUpdate()->first();
        if ($transaction?->reconciled) {
            throw new LogicException('Reconciled Plaid transactions require review before removal.');
        }
        if ($transaction !== null) {
            BankFeedTransaction::where('transaction_id', $transaction->getKey())->where('bank_connection_id', $connection->id)->delete();
            $transaction->delete();
        }
    }

    protected function assertConnectionAuthority(BankConnection $connection): void
    {
        if ($connection->bank_id !== 'plaid') {
            throw new AuthorizationException('Plaid synchronization requires a Plaid connection.');
        }
        $team = Team::find($connection->team_id);
        $owner = User::find($connection->user_id);
        if ($team === null || $owner === null || ! $owner->belongsToTeam($team)) {
            throw new AuthorizationException('Plaid connection must retain an authorized company identity.');
        }
        $actor = auth()->user();
        if ($actor !== null) {
            $actor = $actor->fresh();
            if ($actor === null || (int) $actor->current_team_id !== (int) $team->id) {
                throw new AuthorizationException('Plaid synchronization requires the persisted selected company.');
            }
        } else {
            $actor = $owner;
        }
        if ((int) $team->user_id !== (int) $actor->getKey()
            && ! DB::table('team_user')->where('team_id', $team->id)->where('user_id', $actor->getKey())
                ->whereIn('role', ['admin', 'accountant'])->exists()) {
            throw new AuthorizationException('Plaid synchronization requires a current accounting role.');
        }
    }

    /**
     * Categorize a Plaid transaction based on its category array
     */
    protected function categorizePlaidTransaction(array $plaidTransaction): string
    {
        $categories = $plaidTransaction['category'] ?? [];

        if (empty($categories)) {
            return 'uncategorized';
        }

        // Use the most specific category (last one in the array)
        return strtolower((string) end($categories));
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        Log::error('Plaid transaction sync job permanently failed', [
            'connection_id' => $this->connectionId,
            'exception_type' => $exception::class,
        ]);

        // Notify administrators or mark connection for review
        // This could dispatch an event or send a notification
    }
}
