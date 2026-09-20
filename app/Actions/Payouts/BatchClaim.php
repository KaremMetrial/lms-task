<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Models\PayoutBatch;

final readonly class BatchClaim
{
    private function __construct(
        public PayoutBatch $batch,
        public bool $wasCreated,
    ) {}

    public static function created(PayoutBatch $batch): self
    {
        return new self($batch, true);
    }

    public static function existing(PayoutBatch $batch): self
    {
        return new self($batch, false);
    }
}
