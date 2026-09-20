<?php

declare(strict_types=1);

namespace App\Domain\Subscriptions;

use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

enum Plan: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';

    public function months(): int
    {
        return (int) config("ledger.plans.{$this->value}.months");
    }

    public function price(): Money
    {
        return Money::of(
            (int) config("ledger.plans.{$this->value}.amount_minor"),
            (string) config('ledger.currency'),
        );
    }

    /**
     * The last day of access for a term beginning on $startsOn, inclusive.
     *
     * addMonthsNoOverflow is deliberate. Carbon's default addMonths() overflows:
     * 31 Jan + 1 month becomes 3 March, which would make a "monthly" subscription
     * span three calendar months and produce a nonsense accrual schedule. Clamping
     * gives 28 Feb instead, so the term ends 27 Feb.
     *
     * The accepted consequence: a monthly subscription starting 31 Jan runs 28
     * days rather than 31. It is internally consistent — recognition is by day, so
     * the student pays for and the instructors earn over exactly those 28 days —
     * but it is a real edge, documented in ARCHITECTURE.md rather than hidden.
     */
    public function termEndFor(CarbonImmutable $startsOn): CarbonImmutable
    {
        return $startsOn->addMonthsNoOverflow($this->months())->subDay();
    }
}
