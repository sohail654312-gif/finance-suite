<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BankConnection;
use App\Models\BankFeedTransaction;
use App\Models\Team;
use App\Models\Transaction;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class BankFeedService
{
    protected array $apiConfig;

    public function __construct()
    {
        $this->apiConfig = (array) config('services.bank_feeds', []);
    }

    public function connectBank(array $credentials, string $bankId): BankConnection
    {
        $teamId = $this->ownedTeamId();
        if ($bankId === '' || strlen($bankId) > 255 || $credentials === []) {
            throw new InvalidArgumentException('Bank identity and credentials are required.');
        }

        return BankConnection::create([
            'bank_id' => $bankId,
            'credentials' => $credentials,
            'team_id' => $teamId,
            'user_id' => auth()->id(),
            'status' => 'active',
        ]);
    }

    public function importTransactions(BankConnection $connection): void
    {
        $teamId = $this->ownedTeamId();
        $connection = BankConnection::where('team_id', $teamId)->find($connection->getKey());
        if ($connection === null) {
            throw new AuthorizationException('Bank connection must belong to the selected company.');
        }
        if ($connection->status !== 'active') {
            throw new LogicException('Bank connection must be active.');
        }
        $key = $this->apiConfig['key'] ?? null;
        $url = $this->apiConfig['url'] ?? null;
        if (! is_string($key) || $key === '' || ! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new LogicException('Bank feed is not configured with a trusted HTTPS endpoint.');
        }

        try {
            $response = Http::timeout(30)->connectTimeout(10)->withHeaders([
                'Authorization' => $key,
            ])->get(rtrim($url, '/').'/transactions', [
                'bank_id' => $connection->bank_id,
                'credentials' => $connection->credentials ?? [],
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Bank feed request was unsuccessful.');
            }
        } catch (\Throwable $e) {
            Log::error('Bank feed request failed.', ['exception_type' => $e::class]);
            throw new RuntimeException('Bank feed request failed.');
        }

        $transactions = $response->json('transactions');
        if (! is_array($transactions) || ! array_is_list($transactions)) {
            throw new InvalidArgumentException('Bank feed transactions must be a list.');
        }
        DB::transaction(function () use ($connection, $transactions, $teamId): void {
            $locked = BankConnection::where('team_id', $teamId)->lockForUpdate()->findOrFail($connection->id);
            if ($locked->status !== 'active') {
                throw new LogicException('Bank connection must be active.');
            }
            foreach ($transactions as $transaction) {
                if (! is_array($transaction)) {
                    throw new InvalidArgumentException('Bank feed transaction must be an object.');
                }
                $this->processTransaction($transaction, $locked);
            }
            $locked->update(['last_synced_at' => now()]);
        });
    }

    protected function processTransaction(array $transactionData, BankConnection $connection): Transaction
    {
        $id = $transactionData['id'] ?? null;
        $date = $transactionData['date'] ?? null;
        $amount = $transactionData['amount'] ?? null;
        $description = $transactionData['description'] ?? null;
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ((! is_string($id) && ! is_int($id)) || (string) $id === '' || strlen((string) $id) > 255 || $parsed === false || $parsed->format('Y-m-d') !== $date || (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) || ! preg_match('/^-?\d{1,10}(?:\.\d{1,2})?$/D', (string) $amount) || ! is_string($description)) {
            throw new InvalidArgumentException('Bank feed identity, date, decimal amount and description must be valid.');
        }
        $transaction = Transaction::where('external_id', (string) $id)->lockForUpdate()->first();
        if ($transaction !== null && ((int) $transaction->team_id !== (int) $connection->team_id || (int) $transaction->bank_connection_id !== $connection->id)) {
            throw new LogicException('Bank feed identity belongs to another company or connection.');
        }
        $values = [
            'team_id' => $connection->team_id,
            'bank_connection_id' => $connection->id,
            'transaction_date' => $date,
            'amount' => bcadd((string) $amount, '0', 2),
            'description' => $description,
            'category' => $this->categorizeTransaction($transactionData),
        ];
        if ($transaction === null) {
            $transaction = Transaction::create($values + ['external_id' => (string) $id, 'reconciled' => false, 'status' => 'pending']);
        } else {
            $transaction->fill($values);
            if ($transaction->reconciled && $transaction->isDirty()) {
                throw new LogicException('Reconciled bank feed transactions require review before correction.');
            }
            $transaction->save();
        }

        BankFeedTransaction::updateOrCreate([
            'transaction_id' => $transaction->getKey(),
            'bank_connection_id' => $connection->id,
        ], [
            'raw_data' => $transactionData,
        ]);

        return $transaction;
    }

    protected function ownedTeamId(): int
    {
        if (config('finance-suite.demo')) {
            throw new LogicException('Live bank feeds are disabled in demo mode.');
        }
        $user = auth()->user();
        $teamId = $user?->currentTeam?->getKey();
        if ($user === null || $teamId === null || ! Team::where('user_id', $user->getKey())->whereKey($teamId)->exists()) {
            throw new AuthorizationException('The selected company owner must authorize bank feed access.');
        }

        return (int) $teamId;
    }

    protected function categorizeTransaction(array $transactionData): string
    {
        $description = strtolower((string) $transactionData['description']);

        $categories = [
            'salary' => ['payroll', 'salary', 'wage'],
            'utilities' => ['electric', 'water', 'gas', 'internet'],
            'food' => ['restaurant', 'grocery', 'food'],
            'rent' => ['rent', 'lease', 'housing'],
            'transportation' => ['fuel', 'gas', 'parking', 'transit'],
            'entertainment' => ['movie', 'theatre', 'concert', 'streaming'],
            'shopping' => ['amazon', 'walmart', 'target', 'store'],
            'healthcare' => ['medical', 'doctor', 'pharmacy', 'hospital'],
        ];

        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($description, $keyword)) {
                    return $category;
                }
            }
        }

        return 'uncategorized';
    }
}
