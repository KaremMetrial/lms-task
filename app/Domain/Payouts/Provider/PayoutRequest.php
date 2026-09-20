<?php

declare(strict_types=1);

namespace App\Domain\Payouts\Provider;

use App\Domain\Money\Money;

final readonly class PayoutRequest
{
    public function __construct(
        /** Deterministic, derived from (batch, instructor). Never random. */
        public string $idempotencyKey,
        public int $instructorId,
        public string $accountRef,
        public Money $amount,
    ) {}
}
