<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

final readonly class FanOutSummary
{
    public function __construct(
        /** Rows this call actually inserted. Zero on a replay. */
        public int $itemsCreated,
        public int $skippedBelowMinimum,
        public int $skippedNotPayable,
        /** Rows on the batch in total, whoever created them. */
        public int $totalItems,
    ) {}
}
