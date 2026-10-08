<?php

declare(strict_types=1);

namespace Liberu\Accounting\GeneralLedger\Events;

use Liberu\Accounting\GeneralLedger\Models\JournalEntry;

/** Synchronous authorization boundary; listeners may reject the posting transaction. */
final readonly class AuthorizingJournalPost
{
    public function __construct(
        public JournalEntry $journal,
        public ?string $actor = null,
    ) {}
}
