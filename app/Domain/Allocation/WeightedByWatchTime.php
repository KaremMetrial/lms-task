<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Share proportional to seconds watched within the accrual period.
 *
 * Closer to "paid for attention actually delivered" than the equal-weight rule,
 * at the cost of depending on watch-time telemetry being accurate. Selected with
 * LEDGER_ALLOCATION_STRATEGY=watch_time.
 *
 * Two degenerate cases have to be answered, and neither is obvious:
 *
 *   • No watch time recorded at all, for anyone.
 *     Falls back to equal weight. The student demonstrably engaged — that is why
 *     there are rows at all — so the information we lack is the split, not
 *     whether anyone earned. Returning an empty set would quietly hand the whole
 *     period to the platform whenever telemetry failed, which turns a tracking
 *     outage into unpaid instructors. Dividing by zero is not an option either.
 *
 *   • Some instructors at zero, others not.
 *     Zero-weight instructors receive nothing. That is the strategy's premise
 *     honestly applied: opening a course without watching it delivered no
 *     attention. It is also why this is not the default.
 *
 * Known limitation, deliberately not mitigated here: a single very long video
 * can dominate a period. The usual fix is capping each instructor's weight at a
 * multiple of the median. That is a policy decision with a real fairness
 * trade-off, so it belongs in a documented config knob rather than buried in a
 * weighting function — see docs/ARCHITECTURE.md.
 */
final readonly class WeightedByWatchTime implements RevenueAllocationStrategy
{
    public const NAME = 'watch_time';

    public function __construct(
        private EqualWeightPerEngagedInstructor $fallback = new EqualWeightPerEngagedInstructor,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** @return array<int, int> */
    public function weights(EngagementWindow $window): array
    {
        if ($window->isEmpty()) {
            return [];
        }

        if ($window->totalWatchedSeconds() === 0) {
            return $this->fallback->weights($window);
        }

        $weights = [];

        foreach ($window->engagements as $instructorId => $engagement) {
            $weights[$instructorId] = $engagement->watchedSeconds;
        }

        return $weights;
    }
}
