<?php

declare(strict_types=1);

namespace Liberu\Accounting\Periods\Listeners;

use Liberu\Accounting\Core\Events\PostingToBook;
use Liberu\Accounting\Periods\Exceptions\InvalidPeriodTransition;
use Liberu\Accounting\Periods\Models\AccountingPeriod;

final class EnsurePostingPeriodIsOpen
{
    public function handle(PostingToBook $event): void
    {
        $periods = AccountingPeriod::query()->where('book_id', $event->bookId)
            ->orderBy('id')->lockForUpdate()->get();
        if ($periods->isEmpty()) {
            if (config('accounting-periods.setup_mode', false) && ! app()->isProduction()) {
                return;
            }
            throw new InvalidPeriodTransition('Configure an accounting period before posting. Unconfigured books are restricted to explicit non-production setup mode.');
        }
        $matching = $periods->filter(fn (AccountingPeriod $period): bool => $event->entryDate >= $period->starts_on->toDateString() && $event->entryDate <= $period->ends_on->toDateString());
        if ($matching->count() !== 1) {
            throw new InvalidPeriodTransition('Posting requires exactly one configured accounting period for the journal date.');
        }
        $period = $matching->first();
        if ($period->locked_at !== null || ! $period->isPostingAllowed($event->entryDate)) {
            throw new InvalidPeriodTransition('The accounting period is closed, locked, or outside its posting window.');
        }
    }
}
