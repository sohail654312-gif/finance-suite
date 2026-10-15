<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class ProviderTransactionImporter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): array<string, mixed>  $map
     */
    public function handle(array $rows, ?int $teamId, callable $map): int
    {
        if ($teamId === null || $teamId < 1) {
            throw new LogicException('Provider imports require an owned company.');
        }

        return DB::transaction(function () use ($rows, $teamId, $map): int {
            foreach ($rows as $row) {
                $mapped = $map($row);
                $externalId = (string) ($mapped['external_id'] ?? '');
                $date = (string) ($mapped['date'] ?? '');
                $amount = (string) ($mapped['amount'] ?? '');
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if ($externalId === '' || strlen($externalId) > 255 || $parsed === false || $parsed->format('Y-m-d') !== $date || ! preg_match('/^-?\d{1,10}(?:\.\d{1,2})?$/D', $amount)) {
                    throw new InvalidArgumentException('Provider transaction identity, date and decimal amount must be valid.');
                }
                $accountId = $mapped['account_id'] ?? null;
                if ($accountId !== null && ! Account::whereKey($accountId)->where('team_id', $teamId)->exists()) {
                    throw new LogicException('Provider transaction account belongs to another company.');
                }
                $transaction = Transaction::where('external_id', $externalId)->lockForUpdate()->first();
                if ($transaction !== null && (int) $transaction->team_id !== $teamId) {
                    throw new LogicException('Provider transaction identity is already owned by another company.');
                }
                $values = [
                    'team_id' => $teamId,
                    'transaction_date' => $date,
                    'amount' => bcadd($amount, '0', 2),
                    'description' => (string) ($mapped['description'] ?? ''),
                    'account_id' => $accountId,
                    'type' => (string) ($mapped['type'] ?? 'bank'),
                ];
                if ($transaction === null) {
                    Transaction::create($values + ['external_id' => $externalId, 'reconciled' => false, 'status' => 'pending']);
                } else {
                    $transaction->fill($values);
                    if ($transaction->reconciled && $transaction->isDirty()) {
                        throw new LogicException('Reconciled provider transactions require review before correction.');
                    }
                    $transaction->save();
                }
            }

            return count($rows);
        });
    }

    public function accountId(string $providerColumn, mixed $externalId, ?int $teamId): ?int
    {
        if (! in_array($providerColumn, ['qbo_id', 'xero_id', 'sage_id'], true) || $teamId === null || $teamId < 1) {
            throw new InvalidArgumentException('A supported provider and company are required for account mapping.');
        }
        if ($externalId === null || $externalId === '') {
            return null;
        }
        $id = Account::where($providerColumn, (string) $externalId)->where('team_id', $teamId)->value('id');

        return $id === null ? null : (int) $id;
    }
}
