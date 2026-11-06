<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccountBalance;
use App\Models\BankConnection;
use App\Models\BankFeedTransaction;
use App\Models\Currency;
use App\Models\OutboundPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RevolutService;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Liberu\Accounting\Core\Support\TenantOwnership;
use LogicException;

class RevolutController extends Controller
{
    public function __construct(protected RevolutService $revolutService) {}

    /**
     * Redirect the user to Revolut's OAuth authorization page
     */
    public function redirectToRevolut(Request $request): JsonResponse
    {
        abort_if($this->ownedTeamId($request) === null, 403);
        try {
            $state = Str::random(40);
            Cache::put($this->stateCacheKey($request), $state, now()->addMinutes(10));

            $authorizationUrl = $this->revolutService->getAuthorizationUrl($state);

            return response()->json([
                'success' => true,
                'authorization_url' => $authorizationUrl,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to generate Revolut authorization URL', [
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
     * Handle the OAuth callback from Revolut and store the connection
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $teamId = $this->ownedTeamId($request);
        abort_if($teamId === null, 403);
        $request->validate(['code' => ['nullable', 'string', 'max:4096'], 'state' => ['nullable', 'string', 'max:200']]);
        $code = $request->input('code');
        $state = $request->input('state');

        if (! $code) {
            return response()->json([
                'success' => false,
                'message' => 'Authorization code missing',
            ], 400);
        }

        // Verify OAuth state (CSRF). Stashed in the cache under the authenticated
        // user by redirectToRevolut() — api routes have no session store.
        $expected = Cache::pull($this->stateCacheKey($request));
        if ($expected === null || ! hash_equals($expected, $state ?? '')) {
            Log::warning('Revolut OAuth state mismatch', [
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
            $tokenData = $this->revolutService->exchangeAuthorizationCode($code);
            if (! is_string($tokenData['access_token'] ?? null) || $tokenData['access_token'] === ''
                || (isset($tokenData['refresh_token']) && ! is_string($tokenData['refresh_token']))
                || (isset($tokenData['expires_in']) && (filter_var($tokenData['expires_in'], FILTER_VALIDATE_INT) === false || (int) $tokenData['expires_in'] < 1))) {
                throw new InvalidArgumentException('The provider did not return valid connection credentials.');
            }

            $connection = BankConnection::create([
                'user_id' => $user->id,
                'team_id' => $teamId,
                'bank_id' => 'revolut',
                'institution_name' => 'Revolut Business',
                'revolut_access_token' => $tokenData['access_token'],
                'revolut_refresh_token' => $tokenData['refresh_token'] ?? null,
                'revolut_token_expires_at' => isset($tokenData['expires_in'])
                    ? now()->addSeconds($tokenData['expires_in'])
                    : null,
                'status' => 'active',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Revolut Business account connected successfully',
                'connection' => [
                    'id' => $connection->id,
                    'institution_name' => $connection->institution_name,
                    'status' => $connection->status,
                    'created_at' => $connection->created_at,
                ],
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to store Revolut connection', [
                'user_id' => $request->user()?->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to connect Revolut account',
            ], 500);
        }
    }

    /**
     * List all Revolut connections for the authenticated user
     */
    public function listConnections(Request $request): JsonResponse
    {
        $teamId = $this->ownedTeamId($request);
        abort_if($teamId === null, 403);
        try {
            $user = $request->user();

            $connections = BankConnection::where('team_id', $teamId)
                ->where('bank_id', 'revolut')
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
            Log::error('Failed to list Revolut connections', [
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
     * Get accounts and balances for a Revolut connection
     */
    public function getAccounts(Request $request, BankConnection $connection): JsonResponse
    {
        try {
            if ($connection->bank_id !== 'revolut' || $this->ownedTeamId($request) === null || $connection->team_id !== $this->ownedTeamId($request)) {
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
                $accounts = $this->revolutService->getAccounts($connection);
                if (! array_is_list($accounts)) {
                    throw new InvalidArgumentException('The provider account response must be a list.');
                }
                $syncedAccounts = [];

                foreach ($accounts as $account) {
                    if (! is_array($account) || ! is_string($account['id'] ?? null) || $account['id'] === ''
                        || ! is_string($account['name'] ?? null) || $account['name'] === ''
                        || ! is_string($account['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $account['currency'])
                        || ! is_scalar($account['balance'] ?? null)
                        || ! preg_match('/^-?\d{1,10}(?:\.\d{1,2})?$/D', (string) $account['balance'])) {
                        throw new InvalidArgumentException('The provider account requires a valid identity, currency and exact balance.');
                    }
                    $balance = BankAccountBalance::updateOrCreate(
                        [
                            'bank_connection_id' => $connection->id,
                            'plaid_account_id' => $account['id'],
                        ],
                        [
                            'account_name' => $account['name'],
                            'account_type' => $account['type'] ?? 'current',
                            'account_subtype' => null,
                            'current_balance' => $account['balance'] ?? null,
                            'available_balance' => $account['balance'] ?? null,
                            'iso_currency_code' => $account['currency'] ?? null,
                            'last_updated_at' => now(),
                        ]
                    );
                    $balance->forceFill(['team_id' => $connection->team_id])->save();

                    $syncedAccounts[] = [
                        'id' => $balance->id,
                        'revolut_account_id' => $account['id'],
                        'account_name' => $balance->account_name,
                        'account_type' => $balance->account_type,
                        'current_balance' => $balance->current_balance,
                        'currency' => $balance->iso_currency_code,
                    ];
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
            Log::error('Failed to get Revolut accounts', [
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
     * Sync transactions for a Revolut connection
     */
    public function syncTransactions(Request $request, BankConnection $connection): JsonResponse
    {
        try {
            if ($connection->bank_id !== 'revolut' || $this->ownedTeamId($request) === null || $connection->team_id !== $this->ownedTeamId($request)) {
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
            $from = $connection->last_synced_at
                ? $connection->last_synced_at->toIso8601String()
                : now()->subDays(90)->toIso8601String();

            DB::beginTransaction();
            try {
                $transactions = $this->revolutService->getTransactions($connection, $from);
                if (! array_is_list($transactions)) {
                    throw new InvalidArgumentException('The provider transaction response must be a list.');
                }

                $processedCount = 0;
                foreach ($transactions as $revolutTransaction) {
                    if (! is_array($revolutTransaction)) {
                        throw new InvalidArgumentException('Every provider transaction must be an object.');
                    }
                    $this->processRevolutTransaction($revolutTransaction, $connection);
                    $processedCount++;
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
            Log::error('Failed to sync Revolut transactions', [
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
     * Remove a Revolut connection
     */
    public function removeConnection(Request $request, BankConnection $connection): JsonResponse
    {
        try {
            if ($connection->bank_id !== 'revolut' || $this->ownedTeamId($request) === null || $connection->team_id !== $this->ownedTeamId($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $connection->update(['status' => 'disconnected']);

            return response()->json([
                'success' => true,
                'message' => 'Revolut connection removed successfully',
            ]);
        } catch (Exception $e) {
            Log::error('Failed to remove Revolut connection', [
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
     * Send a single payment via Revolut Business
     */
    public function sendPayment(Request $request, BankConnection $connection): JsonResponse
    {
        $request->validate([
            // Client-supplied idempotency key. Reused verbatim as Revolut's
            // request_id so a retry returns the SAME payment instead of a second
            // transfer (prevents double-spend on network retry / double-submit).
            'idempotency_key' => 'required|string|max:40',
            'account_id' => 'required|string',
            'receiver' => 'required|array',
            'receiver.counterparty_id' => 'required_without:receiver.account_id|string|nullable',
            'receiver.account_id' => 'required_without:receiver.counterparty_id|string|nullable',
            'amount' => 'required|numeric|min:0.01|decimal:0,2',
            'currency' => 'required|string|alpha|size:3',
            'reference' => 'required|string|max:255',
        ]);

        $guardKey = 'revolut_pay:'.$connection->id.':'.$request->input('idempotency_key');

        try {
            if ($connection->bank_id !== 'revolut' || $this->ownedTeamId($request) === null || $connection->team_id !== $this->ownedTeamId($request)) {
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

            // Atomically claim the idempotency key. ponytail: best-effort 24h dup
            // guard; Revolut's request_id (below) is the authoritative dedup, so a
            // legit retry with the same key never double-spends. Forgotten on
            // failure so a corrected retry can proceed.
            if (OutboundPayment::where('bank_connection_id', $connection->id)->where('idempotency_key', $request->input('idempotency_key'))->exists()
                || ! Cache::add($guardKey, true, now()->addHours(24))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate payment: idempotency_key already used',
                ], 409);
            }

            // Route through the maker-checker approval flow. Below the ApprovalRule
            // threshold it auto-approves and the SendApprovedOutboundPayment listener
            // fires the send inline; above threshold it waits for a second approver
            // (surfaced in the PendingApprovals page). The actual Revolut call — with
            // idempotency_key as request_id — happens in that listener.
            $payment = OutboundPayment::create([
                'team_id' => $connection->team_id,
                'bank_connection_id' => $connection->id,
                'requested_by' => $request->user()->id,
                'account_id' => $request->input('account_id'),
                'receiver' => $request->input('receiver'),
                'amount' => $request->input('amount'),
                'currency' => $request->input('currency'),
                'reference' => $request->input('reference'),
                'idempotency_key' => $request->input('idempotency_key'),
                'status' => OutboundPayment::STATUS_PENDING_APPROVAL,
                'approval_status' => 'draft',
            ]);

            $payment->submitForApproval();
            $payment->refresh();

            if ($payment->status === OutboundPayment::STATUS_SENT) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment sent successfully',
                    'payment' => $payment->result,
                ], 201);
            }

            if ($payment->status === OutboundPayment::STATUS_FAILED) {
                Cache::forget($guardKey); // persisted instruction still prevents an ambiguous replay
                Log::error('Revolut payment failed to send', ['payment_id' => $payment->id]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment failed to send',
                ], 500);
            }

            // Above threshold: awaiting a second approver before it sends.
            return response()->json([
                'success' => true,
                'message' => 'Payment queued for approval',
                'payment_id' => $payment->id,
                'status' => $payment->status,
            ], 202);
        } catch (Exception $e) {
            Cache::forget($guardKey);
            Log::error('Failed to send Revolut payment', [
                'connection_id' => $connection->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send payment',
            ], 500);
        }
    }

    /**
     * Send bulk payments via Revolut Business
     */
    public function sendBulkPayment(Request $request, BankConnection $connection): JsonResponse
    {
        $request->validate([
            // See sendPayment: idempotency key guards against a duplicate batch.
            'idempotency_key' => 'required|string|max:40',
            'title' => 'required|string|max:255',
            'schedule_for' => 'nullable|date_format:Y-m-d',
            'payments' => 'required|array|min:1',
            'payments.*.account_id' => 'required|string',
            'payments.*.receiver' => 'required|array',
            'payments.*.receiver.counterparty_id' => 'required_without:payments.*.receiver.account_id|string|nullable',
            'payments.*.receiver.account_id' => 'required_without:payments.*.receiver.counterparty_id|string|nullable',
            'payments.*.amount' => 'required|numeric|min:0.01|decimal:0,2',
            'payments.*.currency' => 'required|string|alpha|size:3',
            'payments.*.reference' => 'required|string|max:255',
        ]);

        $guardKey = 'revolut_bulkpay:'.$connection->id.':'.$request->input('idempotency_key');

        try {
            if ($connection->bank_id !== 'revolut' || $this->ownedTeamId($request) === null || $connection->team_id !== $this->ownedTeamId($request)) {
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

            // Best-effort dup guard (payment-drafts require separate approval
            // before money moves, so double-submit only risks a duplicate draft).
            if (! Cache::add($guardKey, true, now()->addHours(24))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate bulk payment: idempotency_key already used',
                ], 409);
            }

            $result = $this->revolutService->sendBulkPayment(
                $connection,
                $request->input('title'),
                $request->input('payments'),
                $request->input('schedule_for'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Bulk payment submitted successfully',
                'payment_draft' => $result,
            ], 201);
        } catch (Exception $e) {
            Cache::forget($guardKey);
            Log::error('Failed to send Revolut bulk payment', [
                'connection_id' => $connection->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send bulk payment',
            ], 500);
        }
    }

    /**
     * Process a Revolut transaction and store in the database
     */
    protected function processRevolutTransaction(array $revolutTransaction, BankConnection $connection): void
    {
        $transactionId = is_scalar($revolutTransaction['id'] ?? null) ? (string) $revolutTransaction['id'] : '';
        $legs = $revolutTransaction['legs'] ?? [];
        if (! is_array($legs) || ! array_is_list($legs) || count($legs) !== 1 || ! is_array($legs[0])) {
            throw new InvalidArgumentException('A Revolut import requires exactly one unambiguous transaction leg.');
        }
        $amount = is_scalar($legs[0]['amount'] ?? null) ? (string) $legs[0]['amount'] : '';
        $currencyCode = is_string($legs[0]['currency'] ?? null) ? $legs[0]['currency'] : '';
        $timestamp = $revolutTransaction['completed_at'] ?? $revolutTransaction['created_at'] ?? '';
        $timestamp = is_string($timestamp) ? $timestamp : '';
        $date = substr($timestamp, 0, 10);
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($transactionId === '' || strlen($transactionId) > 255 || count($legs) !== 1
            || ! preg_match('/^-?\\d{1,10}(?:\\.\\d{1,2})?$/D', $amount)
            || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}(?:[ T](?:[01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})?)?$/D', $timestamp)
            || $parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Revolut transactions require an unambiguous leg, valid identity, date and exact decimal amount.');
        }
        new DateTimeImmutable($timestamp);
        $currency = Currency::query()->where('code', $currencyCode)->first();
        if ($currency === null) {
            throw new InvalidArgumentException('Revolut transaction currency must be configured before import.');
        }

        $transaction = Transaction::where('external_id', $transactionId)->lockForUpdate()->first();
        if ($transaction !== null && ((int) $transaction->team_id !== (int) $connection->team_id
            || (int) $transaction->bank_connection_id !== (int) $connection->id)) {
            throw new LogicException('Revolut transaction identity belongs to another company or connection.');
        }
        $values = [
            'team_id' => $connection->team_id,
            'bank_connection_id' => $connection->id,
            'currency_id' => $currency->getKey(),
            'transaction_date' => $date,
            'amount' => bccomp($amount, '0', 2) < 0 ? bcsub('0', $amount, 2) : bcadd($amount, '0', 2),
            'type' => bccomp($amount, '0', 2) < 0 ? 'debit' : 'credit',
            'description' => $revolutTransaction['reference'] ?? $revolutTransaction['merchant']['name'] ?? 'Revolut transaction',
            'category' => $this->categorizeRevolutTransaction($revolutTransaction),
            'status' => $this->mapRevolutState(is_string($revolutTransaction['state'] ?? null) ? $revolutTransaction['state'] : ''),
        ];
        if ($transaction === null) {
            $transaction = Transaction::create($values + ['external_id' => $transactionId, 'reconciled' => false]);
        } else {
            $transaction->fill($values);
            if ($transaction->reconciled && $transaction->isDirty()) {
                throw new LogicException('Reconciled Revolut transactions require review before correction.');
            }
            $transaction->save();
        }

        BankFeedTransaction::updateOrCreate(
            [
                'transaction_id' => $transaction->getKey(),
                'bank_connection_id' => $connection->id,
            ],
            [
                'raw_data' => $revolutTransaction,
            ]
        );
    }

    /**
     * Map Revolut transaction state to internal status
     */
    protected function mapRevolutState(string $state): string
    {
        return match ($state) {
            'pending' => 'pending',
            'completed' => 'posted',
            'declined' => 'declined',
            'failed' => 'failed',
            'reverted' => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * Categorize a Revolut transaction based on its type/merchant info
     */
    protected function categorizeRevolutTransaction(array $revolutTransaction): string
    {
        $type = strtolower(is_string($revolutTransaction['type'] ?? null) ? $revolutTransaction['type'] : '');

        $typeMap = [
            'transfer' => 'transfer',
            'card_payment' => 'payment',
            'card_refund' => 'refund',
            'atm' => 'cash',
            'fee' => 'fee',
            'topup' => 'topup',
            'fx' => 'exchange',
        ];

        if (isset($typeMap[$type])) {
            return $typeMap[$type];
        }

        $merchantCategory = $revolutTransaction['merchant']['category'] ?? null;
        if ($merchantCategory) {
            return strtolower((string) $merchantCategory);
        }

        return 'uncategorized';
    }

    private function ownedTeamId(Request $request): ?int
    {
        $user = $request->user();

        return $user instanceof User ? TenantOwnership::currentTeamId($user->fresh()) : null;
    }

    private function stateCacheKey(Request $request): string
    {
        return 'revolut_oauth_state:'.$request->user()->id.':'.$this->ownedTeamId($request);
    }
}
