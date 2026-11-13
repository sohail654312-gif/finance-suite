<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncPlaidTransactionsJob;
use App\Models\BankAccountBalance;
use App\Models\BankConnection;
use App\Services\PlaidService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PlaidController extends Controller
{
    public function __construct(protected PlaidService $plaidService) {}

    /**
     * Create a link token for Plaid Link initialization
     * Supports both initial connection and update mode for re-authentication
     */
    public function createLinkToken(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $language = $request->input('language', 'en');
            $connectionId = $request->input('connection_id');

            $accessToken = null;

            // If connection_id is provided, this is update mode for re-authentication
            if ($connectionId) {
                $connection = BankConnection::find($connectionId);

                // Verify team ownership (connections are team-shared)
                if (! $connection || $connection->team_id !== ($user->current_team_id ?? -1)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Connection not found or unauthorized',
                    ], 404);
                }

                $accessToken = $connection->plaid_access_token;
            }

            $tokenData = $this->plaidService->createLinkToken($user->id, $language, $accessToken);

            return response()->json([
                'success' => true,
                'link_token' => $tokenData['link_token'],
                'expiration' => $tokenData['expiration'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to create Plaid link token', [
                'user_id' => $request->user()->id,
                'exception_type' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create link token.',
            ], 500);
        }
    }

    /**
     * Store a new bank connection after Plaid Link success
     */
    public function storeConnection(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'public_token' => 'required|string',
            'institution_id' => 'required|string',
            'institution_name' => 'required|string',
            'accounts' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $user = $request->user();

            // Exchange public token for access token
            $tokenData = $this->plaidService->exchangePublicToken($request->public_token);
            foreach (['access_token', 'item_id'] as $field) {
                if (! is_string($tokenData[$field] ?? null) || trim($tokenData[$field]) === '') {
                    throw new DomainException('Plaid did not return a complete connection identity.');
                }
            }

            // Create bank connection
            $connection = BankConnection::create([
                'user_id' => $user->id,
                'team_id' => $user->current_team_id,
                'bank_id' => $request->institution_id,
                'institution_name' => $request->institution_name,
                'plaid_access_token' => $tokenData['access_token'],
                'plaid_item_id' => $tokenData['item_id'],
                'plaid_institution_id' => $request->institution_id,
                'status' => 'active',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bank connection created successfully',
                'connection' => [
                    'id' => $connection->id,
                    'institution_name' => $connection->institution_name,
                    'status' => $connection->status,
                    'created_at' => $connection->created_at,
                ],
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Failed to store Plaid connection', [
                'user_id' => $request->user()->id,
                'exception_type' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to store connection.',
            ], 500);
        }
    }

    /**
     * List all bank connections for the authenticated user
     */
    public function listConnections(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $connections = BankConnection::where('team_id', $user->current_team_id ?? -1)
                ->select([
                    'id',
                    'institution_name',
                    'bank_id',
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
        } catch (Throwable $e) {
            Log::error('Failed to list bank connections', [
                'user_id' => $request->user()->id,
                'exception_type' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve connections',
            ], 500);
        }
    }

    /**
     * Sync transactions from Plaid for a specific connection
     */
    public function syncTransactions(Request $request, BankConnection $connection): JsonResponse
    {
        try {
            if ($connection->team_id !== ($request->user()->current_team_id ?? -1)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }
            if ($connection->status !== 'active') {
                return response()->json(['success' => false, 'message' => 'Connection is not active'], 400);
            }

            $job = new SyncPlaidTransactionsJob((int) $connection->getKey());
            $job->handle($this->plaidService);

            return response()->json([
                'success' => true,
                'message' => 'Transactions synced successfully',
                'summary' => $job->summary,
                'last_synced_at' => $connection->fresh()->last_synced_at,
            ]);
        } catch (AuthorizationException $error) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        } catch (Throwable $error) {
            Log::error('Failed to sync transactions', [
                'connection_id' => $connection->id,
                'exception_type' => $error::class,
            ]);

            return response()->json(['success' => false, 'message' => 'Failed to sync transactions.'], 500);
        }
    }

    /**
     * Remove a bank connection
     */
    public function removeConnection(Request $request, BankConnection $connection): JsonResponse
    {
        try {
            // Verify team ownership (connections are team-shared)
            if ($connection->team_id !== ($request->user()->current_team_id ?? -1)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            DB::beginTransaction();
            try {
                // Remove from Plaid
                if ($connection->plaid_access_token) {
                    $this->plaidService->removeItem($connection->plaid_access_token);
                }

                // Soft delete or hard delete based on preference
                $connection->update(['status' => 'disconnected']);
                // Or: $connection->delete();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Bank connection removed successfully',
                ]);
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            Log::error('Failed to remove bank connection', [
                'connection_id' => $connection->id,
                'exception_type' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove connection.',
            ], 500);
        }
    }

    /**
     * Get account balances for a connection
     */
    public function getBalances(Request $request, BankConnection $connection): JsonResponse
    {
        try {
            // Verify team ownership (connections are team-shared)
            if ($connection->team_id !== ($request->user()->current_team_id ?? -1)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            // Check connection status
            if ($connection->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Connection is not active',
                ], 400);
            }

            DB::beginTransaction();
            try {
                // Get balances from Plaid
                $balanceData = $this->plaidService->getBalances($connection->plaid_access_token);

                $accounts = $balanceData['accounts'] ?? null;
                if (! is_array($accounts) || ! array_is_list($accounts)) {
                    throw new DomainException('Plaid did not return an account list.');
                }
                $syncedAccounts = [];
                $seenAccounts = [];

                foreach ($accounts as $account) {
                    $account = $this->validateBalanceAccount($account);
                    $accountId = $account['account_id'];
                    if (isset($seenAccounts[$accountId])) {
                        throw new DomainException('Plaid returned the same balance account more than once.');
                    }
                    $seenAccounts[$accountId] = true;
                    $balance = BankAccountBalance::updateOrCreate(
                        [
                            'bank_connection_id' => $connection->id,
                            'plaid_account_id' => $account['account_id'],
                        ],
                        [
                            'account_name' => $account['name'],
                            'account_type' => $account['type'],
                            'account_subtype' => $account['subtype'] ?? null,
                            'current_balance' => $account['balances']['current'] ?? null,
                            'available_balance' => $account['balances']['available'] ?? null,
                            'limit_amount' => $account['balances']['limit'] ?? null,
                            'iso_currency_code' => $account['balances']['iso_currency_code'] ?? null,
                            'unofficial_currency_code' => $account['balances']['unofficial_currency_code'] ?? null,
                            'last_updated_at' => now(),
                        ]
                    );
                    $balance->forceFill(['team_id' => $connection->team_id])->save();

                    $syncedAccounts[] = [
                        'id' => $balance->id,
                        'account_name' => $balance->account_name,
                        'account_type' => $balance->account_type,
                        'account_subtype' => $balance->account_subtype,
                        'current_balance' => $balance->current_balance,
                        'available_balance' => $balance->available_balance,
                        'currency' => $balance->iso_currency_code ?? $balance->unofficial_currency_code,
                    ];
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Balances synced successfully',
                    'accounts' => $syncedAccounts,
                ]);
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            Log::error('Failed to get balances', [
                'connection_id' => $connection->id,
                'exception_type' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get balances.',
            ], 500);
        }
    }

    private function validateBalanceAccount(mixed $account): array
    {
        if (! is_array($account) || ! is_array($account['balances'] ?? null)) {
            throw new DomainException('Plaid returned a malformed balance account.');
        }
        foreach (['account_id', 'name', 'type'] as $field) {
            if (! is_string($account[$field] ?? null) || trim($account[$field]) === '' || strlen($account[$field]) > 255) {
                throw new DomainException('Plaid returned an invalid balance account identity.');
            }
        }
        $balances = $account['balances'];
        foreach (['current', 'available', 'limit'] as $field) {
            $amount = $balances[$field] ?? null;
            if ($amount !== null && ((! is_int($amount) && ! is_float($amount) && ! is_string($amount))
                || ! preg_match('/^-?\d{1,13}(?:\.\d{1,2})?$/D', (string) $amount))) {
                throw new DomainException('Plaid balance exceeds the supported decimal precision.');
            }
        }
        $currency = $balances['iso_currency_code'] ?? null;
        if ($currency !== null && (! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency))) {
            throw new DomainException('Plaid returned an invalid balance currency.');
        }
        foreach ([$account['subtype'] ?? null, $balances['unofficial_currency_code'] ?? null] as $optional) {
            if ($optional !== null && (! is_string($optional) || strlen($optional) > 255)) {
                throw new DomainException('Plaid returned invalid account metadata.');
            }
        }

        return $account;
    }

    /**
     * Handle OAuth redirect from Plaid Link
     * This endpoint receives the OAuth state after user authentication at their bank
     */
    public function handleOAuthRedirect(Request $request): JsonResponse
    {
        try {
            $oauthStateId = $request->input('oauth_state_id');

            if (! is_string($oauthStateId) || trim($oauthStateId) === '' || strlen($oauthStateId) > 255) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing OAuth state ID',
                ], 400);
            }

            // Return success - the frontend will handle completing the Link flow
            return response()->json([
                'success' => true,
                'message' => 'OAuth redirect received successfully',
                'oauth_state_id' => $oauthStateId,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to handle Plaid OAuth redirect', [
                'exception_type' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to handle OAuth redirect',
            ], 500);
        }
    }
}
