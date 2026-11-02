<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\QboConnection;
use App\Models\SageConnection;
use App\Models\XeroConnection;
use App\Services\QuickBooksService;
use App\Services\SageService;
use App\Services\XeroService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

class SyncAccountingProviderConnectionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public string $provider, public int $connectionId)
    {
        if (! in_array($provider, ['qbo', 'xero', 'sage'], true) || $connectionId < 1) {
            throw new InvalidArgumentException('A supported provider and connection are required.');
        }
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->provider.':'.$this->connectionId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(30)->expireAfter(90)];
    }

    public function handle(): void
    {
        if (config('finance-suite.demo', false)) {
            throw new LogicException('Live provider synchronization is disabled in the demo.');
        }
        $connection = match ($this->provider) {
            'qbo' => QboConnection::find($this->connectionId),
            'xero' => XeroConnection::find($this->connectionId),
            'sage' => SageConnection::find($this->connectionId),
            default => throw new InvalidArgumentException('Unsupported accounting provider.'),
        };
        if ($connection === null || $connection->status !== 'active') {
            return;
        }
        if (! $connection->team_id || ! $connection->team()->exists()) {
            throw new LogicException('Provider synchronization requires an owned company connection.');
        }
        if ($connection instanceof QboConnection) {
            app(QuickBooksService::class)->sync($connection);
        } elseif ($connection instanceof XeroConnection) {
            app(XeroService::class)->sync($connection);
        } else {
            app(SageService::class)->sync($connection);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Do not log provider payloads, tokens or arbitrary exception messages.
        Log::error('Accounting provider synchronization failed', [
            'provider' => $this->provider,
            'connection_id' => $this->connectionId,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
