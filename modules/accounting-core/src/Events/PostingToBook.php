<?php

declare(strict_types=1);

namespace Liberu\Accounting\Core\Events;

/** Synchronous validation boundary; listeners may reject the posting transaction. */
final readonly class PostingToBook
{
    public function __construct(public int $bookId, public string $entryDate) {}
}
