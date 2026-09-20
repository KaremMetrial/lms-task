<?php

declare(strict_types=1);

namespace App\Domain\Recognition;

use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Splits a prepaid subscription term into the calendar months it spans, and
 * decides how much revenue is recognised in each.
 *
 * ── Why this class exists ────────────────────────────────────────────────────
 *
 * The student pays for the whole term on day one. The money arrives immediately;
 * it is not earned immediately. Revenue is recognised straight-line by day, so at
 * any moment "how much of this payment has been earned" is a subtraction rather
 * than a judgement call.
 *
 * That single choice is what makes a mid-term refund a no-op against instructor
 * balances: the unearned portion was never recognised, so it was never payable,
 * so it was never paid.
 *
 * ── Why calendar months, not days ────────────────────────────────────────────
 *
 * Day-level recognition would mean 500k subscriptions × 365 days = 182M rows a
 * year. Monthly buckets give ~6M and land in the size the brief describes. The
 * cost is that a cancellation falls mid-period, which is handled by prorating
 * within that one period — a bounded, testable amount of arithmetic.
 *
 * ── Exactness ────────────────────────────────────────────────────────────────
 *
 * Prorating by day means every period is a floor, and floors do not sum to the
 * total. Rather than scatter the residual, THE FINAL PERIOD ABSORBS IT: its amount
 * is `total − Σ(all previous)`. One rule, exact by construction, and the
 * adjustment sits where it is least surprising — a partial final month.
 */
final readonly class RecognitionSchedule
{
    /** @param list<AccrualPeriod> $periods */
    private function __construct(
        public array $periods,
        public Money $total,
    ) {}

    /**
     * @param  CarbonImmutable  $termStartsOn  first day of access
     * @param  CarbonImmutable  $termEndsOn  last day of access, inclusive — as SOLD,
     *                                       which stays the proration denominator even
     *                                       when recognition is cut short
     * @param  CarbonImmutable|null  $recognitionEndsOn  where recognition actually stops
     *                                                   (a cancellation date), defaults to
     *                                                   the full term
     */
    public static function build(
        Money $total,
        CarbonImmutable $termStartsOn,
        CarbonImmutable $termEndsOn,
        ?CarbonImmutable $recognitionEndsOn = null,
    ): self {
        $termStartsOn = $termStartsOn->startOfDay();
        $termEndsOn = $termEndsOn->startOfDay();

        if ($termEndsOn->lessThan($termStartsOn)) {
            throw new \InvalidArgumentException(
                'A subscription term cannot end before it starts: '
                ."{$termStartsOn->toDateString()} → {$termEndsOn->toDateString()}."
            );
        }

        $recognitionEndsOn = ($recognitionEndsOn ?? $termEndsOn)->startOfDay();

        // A cancellation can only shorten recognition, never extend it.
        if ($recognitionEndsOn->greaterThan($termEndsOn)) {
            $recognitionEndsOn = $termEndsOn;
        }

        // Cancelled before it began: nothing was ever earned.
        if ($recognitionEndsOn->lessThan($termStartsOn)) {
            return new self([], $total);
        }

        // The denominator is the term AS SOLD. Using the shortened window instead
        // would silently inflate the daily rate, so a student who cancelled early
        // would have earned the platform the same money faster — turning a
        // cancellation into a revenue event. It must stay the full term.
        $termDays = (int) $termStartsOn->diffInDays($termEndsOn) + 1;

        $slices = self::monthlySlices($termStartsOn, $recognitionEndsOn);

        // Is recognition running to the very end of the term as sold? Only then may
        // the final period absorb the residual up to the full amount.
        $isFullTerm = $recognitionEndsOn->equalTo($termEndsOn);

        $periods = [];
        $allocatedMinor = 0;
        $lastIndex = count($slices) - 1;

        foreach ($slices as $index => $slice) {
            [$key, $startsOn, $endsOn, $days] = $slice;

            if ($index === $lastIndex && $isFullTerm) {
                // Exact by construction: whatever rounding lost along the way lands
                // here, so the schedule always sums to the amount actually paid.
                $grossMinor = $total->minor - $allocatedMinor;
            } else {
                // Floor. For a truncated term the periods legitimately sum to LESS
                // than the total — the remainder is unearned and gets refunded.
                $grossMinor = self::proratedMinor($total->minor, $days, $termDays);
            }

            $allocatedMinor += $grossMinor;

            $periods[] = new AccrualPeriod(
                key: $key,
                startsOn: $startsOn,
                endsOn: $endsOn,
                days: $days,
                gross: Money::of($grossMinor, $total->currency),
            );
        }

        return new self($periods, $total);
    }

    /**
     * The calendar months between two dates, each clamped to the window.
     *
     * @return list<array{0: string, 1: CarbonImmutable, 2: CarbonImmutable, 3: int}>
     */
    private static function monthlySlices(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $slices = [];
        $cursor = $from->startOfMonth();

        while ($cursor->lessThanOrEqualTo($to)) {
            $monthEnd = $cursor->endOfMonth()->startOfDay();

            $startsOn = $cursor->lessThan($from) ? $from : $cursor;
            $endsOn = $monthEnd->greaterThan($to) ? $to : $monthEnd;

            $slices[] = [
                $cursor->format('Y-m'),
                $startsOn,
                $endsOn,
                (int) $startsOn->diffInDays($endsOn) + 1,
            ];

            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $slices;
    }

    private static function proratedMinor(int $totalMinor, int $days, int $termDays): int
    {
        if ($totalMinor !== 0 && $days > intdiv(PHP_INT_MAX, abs($totalMinor))) {
            throw new \RuntimeException('Proration would overflow PHP_INT_MAX.');
        }

        return (int) floor(($totalMinor * $days) / $termDays);
    }

    // ── Queries ─────────────────────────────────────────────────────────────

    public function findPeriod(string $key): ?AccrualPeriod
    {
        foreach ($this->periods as $period) {
            if ($period->key === $key) {
                return $period;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(fn (AccrualPeriod $p): string => $p->key, $this->periods);
    }

    /** Sum of everything this schedule recognises. */
    public function recognisedTotal(): Money
    {
        return array_reduce(
            $this->periods,
            fn (Money $carry, AccrualPeriod $p): Money => $carry->plus($p->gross),
            Money::zero($this->total->currency),
        );
    }

    /**
     * Money paid but never earned — the refundable amount under the pro-rata
     * policy. Zero for a term that ran to completion.
     */
    public function unearnedTotal(): Money
    {
        return $this->total->minus($this->recognisedTotal());
    }

    public function totalDaysRecognised(): int
    {
        return array_sum(array_map(fn (AccrualPeriod $p): int => $p->days, $this->periods));
    }
}
