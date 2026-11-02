<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccountBalance;
use App\Models\BankConnection;
use App\Models\BankFeedTransaction;
use App\Models\Transaction;
use App\Services\WiseService;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class WiseController extends Controller
{
    public function __construct(protected WiseService $wiseService) {}

    /**
     * Redirect the user to Wise's OAuth authorization page
     */
    public function redirectToWise(Request $request): JsonResponse
    {
        try {
            $state = Str::random(40);
            Cache::put($this->stateCacheKey($request), $state, now()->addMinutes(10));

            $authorizationUrl = $this->wiseService->getAuthorizationUrl($state);

            return response()->json([
                'success' => true,
                'authorization_url' => $authorizationUrl,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to generate Wise authorization URL', [
                'user_id' => $request->user()->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate authorization URL',
            ], 500);
        }
    }

    /**
     * Handle the OAuth callback from Wise and store the connection
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $request->validate(['code' => ['nullable', 'string'], 'state' => ['nullable', 'string']]);
        $code = $request->input('code');
        $state = $request->input('state');

        if (! $code) {
            return response()->json([
                'success' => false,
                'message' => 'Authorization code missing',
            ], 400);
        }

        // Verify OAuth state (CSRF). Stashed in the cache under the authenticated
        // user by redirectToWise() — api routes have no session store.
        $expected = Cache::pull($this->stateCacheKey($request));
        if ($expected === null || ! hash_equals($expected, $state ?? '')) {
            Log::warning('Wise OAuth state mismatch', [
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid OAuth state',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $user = $request->user();
            $tokenData = $this->wiseService->exchangeAuthorizationCode($code);

            $connection = BankConnection::create([
                'user_id' => $user->id,
                'team_id' => $user->current_team_id,
                'bank_id' => 'wise',
                'institution_name' => 'Wise',
                'wise_access_token' => $tokenData['access_token'],
                'wise_refresh_token' => $tokenData['refresh_token'] ?? null,
                'wise_token_expires_at' => isset($tokenData['expires_in'])
                    ? now()->addSeconds($tokenData['expires_in'])
                    : null,
                'status' => 'active',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Wise account connected successfully',
                'connection' => [
                    'id' => $connection->id,
                    'institution_name' => $connection->institution_name,
                    'status' => $connection->status,
                    'created_at' => $connection->created_at,
                ],
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to store Wise connection', [
                'user_id' => $request->user()?->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to connect Wise account',
            ], 500);
        }
    }

    /**
     * List all Wise connections for the authenticated user
     */
    public function listConnections(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $connections = BankConnection::where('team_id', $user->current_team_id ?? -1)
                ->where('bank_id', 'wise')
                ->select([
                    'id',
                    'institution_name',
                    'status',
                    'last_synced_at',
                    'created_at',
                    'updated_at',
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'connections' => $connections,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to list Wise connections', [
                'user_id' => $request->user()->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve connections',
            ], 500);
        }
    }

    /**
     * Get profiles and balances for a Wise connection
     */
    public function getAccounts(Request $request, BankConnection $connection): JsonResponse
    {
        try {
            if ($connection->bank_id !== 'wise' || $connection->team_id !== ($request->user()->current_team_id ?? -1)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            if ($connection->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Connection is not active',
                ], 400);
            }

            DB::beginTransaction();
            try {
                $profiles = $this->wiseService->getProfiles($connection);
                $syncedAccounts = [];

                foreach ($profiles as $profile) {
                    $profileId = $profile['id'];
                    $balances = $this->wiseService->getBalances($connection, $profileId);

                    foreach ($balances as $balance) {
                        $accountBalance = BankAccountBalance::updateOrCreate(
                            [
                                'bank_connection_id' => $connection->id,
                                'plaid_account_id' => 'wise_'.$profileId.'_'.($balance['id'] ?? $balance['currency']),
                            ],
                            [
                                'account_name' => ($profile['type'] ?? 'personal').' - '.($balance['currency'] ?? ''),
                                'account_type' => $profile['type'] ?? 'personal',
                                'account_subtype' => null,
                                'current_balance' => $balance['amount']['value'] ?? null,
                                'available_balance' => $balance['amount']['value'] ?? null,
                                'iso_currency_code' => $balance['currency'] ?? null,
                                'last_updated_at' => now(),
                            ]
                        );

                        $syncedAccounts[] = [
                            'id' => $accountBalance->id,
                            'wise_profile_id' => $profileId,
                            'wise_balance_id' => $balance['id'] ?? null,
                            'account_name' => $accountBalance->account_name,
                            'account_type' => $accountBalance->account_type,
                            'current_balance' => $accountBalance->current_balance,
                            'currency' => $accountBalance->iso_currency_code,
                        ];
                    }
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Accounts synced successfully',
                    'accounts' => $syncedAccounts,
                ]);
            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            Log::error('Failed to get Wise accounts', [
                'connection_id' => $connection->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get accounts',
            ], 500);
        }
    }

    /**
     * Sync transfers for a Wise connection
     */
    public function syncTransactions(Request $request, BankConnection $connection): JsonResponse
    {
        try {
            if ($connection->bank_id !== 'wise' || $connection->team_id !== ($request->user()->current_team_id ?? -1)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            if ($connection->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Connection is not active',
                ], 400);
            }

            // Determine date range: sync from last sync or last 90 days
            $createdDateStart = $connection->last_synced_at
                ? $connection->last_synced_at->toIso8601String()
                : now()->subDays(90)->toIso8601String();

            DB::beginTransaction();
            try {
                $profiles = $this->wiseService->getProfiles($connection);
                $processedCount = 0;

                foreach ($profiles as $profile) {
                    $profileId = $profile['id'];
                    $transfers = $this->wiseService->getTransfers(
                        $connection,
                        $profileId,
                        $createdDateStart
                    );

                    foreach ($transfers as $wiseTransfer) {
                        $this->processWiseTransfer($wiseTransfer, $connection);
                        $processedCount++;
                    }
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Transactions synced successfully',
                    'summary' => [
                        'total_processed' => $processedCount,
                    ],
                    'last_synced_at' => $connection->fresh()->last_synced_at,
                ]);
            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            Log::error('Failed to sync Wise transactions', [
                'connection_id' => $connection->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync transactions',
            ], 500);
        }
    }

    /**
     * Remove a Wise connection
     */
    public function removeConnection(Request $request, BankConnection $connection): JsonResponse
    {
        try {
            if ($connection->bank_id !== 'wise' || $connection->team_id !== ($request->user()->current_team_id ?? -1)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $connection->update(['status' => 'disconnected']);

            return response()->json([
                'success' => true,
                'message' => 'Wise connection removed successfully',
            ]);
        } catch (Exception $e) {
            Log::error('Failed to remove Wise connection', [
                'connection_id' => $connection->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove connection',
            ], 500);
        }
    }

    /**
     * Process a Wise transfer and store in the database
     */
    protected function processWiseTransfer(array $wiseTransfer, BankConnection $connection): void
    {
        $transferId = (string) ($wiseTransfer['id'] ?? '');
        $amount = (string) ($wiseTransfer['sourceValue'] ?? '');
        $createdAt = (string) ($wiseTransfer['created'] ?? '');
        $date = substr($createdAt, 0, 10);
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($transferId === '' || strlen($transferId) > 255
            || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T](?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $createdAt)
            || $parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Wise transfers require a valid identity, date and decimal amount.');
        }
        new DateTimeImmutable($createdAt);

        $transaction = Transaction::where('external_id', $transferId)->lockForUpdate()->first();
        if ($transaction !== null && ((int) $transaction->team_id !== (int) $connection->team_id
            || (int) $transaction->bank_connection_id !== (int) $connection->id)) {
            throw new LogicException('Wise transfer identity belongs to another company or connection.');
        }
        $values = [
            'team_id' => $connection->team_id,
            'bank_connection_id' => $connection->id,
            'transaction_date' => $date,
            'amount' => bcadd($amount, '0', 2),
            'type' => 'debit',
            'description' => $wiseTransfer['details']['reference'] ?? $wiseTransfer['reference'] ?? 'Wise transfer',
            'category' => 'transfer',
            'status' => $this->mapWiseStatus($wiseTransfer['status'] ?? ''),
        ];
        if ($transaction === null) {
            $transaction = Transaction::create($values + ['external_id' => $transferId, 'reconciled' => false]);
        } else {
            $transaction->fill($values);
            if ($transaction->reconciled && $transaction->isDirty()) {
                throw new LogicException('Reconciled Wise transfers require review before correction.');
            }
            $transaction->save();
        }

        BankFeedTransaction::updateOrCreate(
            [
                'transaction_id' => $transaction->getKey(),
                'bank_connection_id' => $connection->id,
            ],
            [
                'raw_data' => $wiseTransfer,
            ]
        );
    }

    /**
     * Map Wise transfer status to internal status
     */
    protected function mapWiseStatus(string $status): string
    {
        return match ($status) {
            'incoming_payment_waiting', 'incoming_payment_initiated', 'processing', 'funds_converted' => 'pending',
            'outgoing_payment_sent' => 'posted',
            'cancelled' => 'cancelled',
            'bounced_back', 'funds_refunded', 'charged_back' => 'failed',
            default => 'pending',
        };
    }

    private function stateCacheKey(Request $request): string
    {
        return 'wise_oauth_state:'.$request->user()->id;
    }
}
