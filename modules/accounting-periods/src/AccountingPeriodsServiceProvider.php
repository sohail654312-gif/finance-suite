<?php

declare(strict_types=1);

namespace Liberu\Accounting\Periods;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Liberu\Accounting\Core\Events\PostingToBook;
use Liberu\Accounting\Periods\Listeners\EnsurePostingPeriodIsOpen;

final class AccountingPeriodsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/accounting-periods.php', 'accounting-periods');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        Event::listen(PostingToBook::class, EnsurePostingPeriodIsOpen::class);
    }
}
