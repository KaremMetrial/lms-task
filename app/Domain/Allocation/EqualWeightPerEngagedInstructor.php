<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Every instructor the student engaged with in the period gets an equal share.
 *
 * The default strategy, chosen over watch-time weighting for three reasons:
 *
 *   1. It is hard to game. Revenue proportional to watch time rewards padding
 *      lesson length; an equal share per engaged instructor rewards being worth
 *      opening at all.
 *   2. It does not depend on telemetry. Trustworthy watch-time data needs
 *      heartbeats, dedup and bot filtering, and it arrives late. Balances that
 *      change after they have been paid are worse than balances that are blunt.
 *   3. It is explainable. "This student opened your course and two others, so you
 *      got a third" is a conversation an instructor can verify.
 *
 * The accepted unfairness: 40 hours of instructor A and one lesson of instructor
 * B split 50/50. That is a real cost, and it is bounded and visible — which is
 * why it was preferred over a weighting function whose failure mode is silent.
 */
final readonly class EqualWeightPerEngagedInstructor implements RevenueAllocationStrategy
{
    public const NAME = 'equal_weight';

    public function name(): string
    {
        return self::NAME;
    }

    /** @return array<int, int> */
    public function weights(EngagementWindow $window): array
    {
        // Weight 1 each. The window is already aggregated per instructor, so an
        // instructor with several watched courses still counts once.
        return array_fill_keys($window->instructorIds(), 1);
    }
}
