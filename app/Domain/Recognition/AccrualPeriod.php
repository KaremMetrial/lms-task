<?php

declare(strict_types=1);

namespace App\Domain\Recognition;

use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * One calendar month's slice of a subscription term, with the revenue recognised
 * for it.
 */
final readonly class AccrualPeriod
{
    public function __construct(
        /** 'YYYY-MM' */
        public string $key,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public int $days,
        public Money $gross,
    ) {}

    public function containsDate(CarbonImmutable $date): bool
    {
        return $date->betweenIncluded($this->startsOn, $this->endsOn);
    }
}
