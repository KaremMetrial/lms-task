<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Decides the RELATIVE WEIGHTS of the instructors sharing one accrual period's
 * revenue. It does not divide money.
 *
 * That separation is deliberate. Money::allocate() owns the arithmetic and the
 * "parts sum to the total, exactly" invariant; a strategy only answers "in what
 * proportion?". So a new strategy is a pure weighting decision and cannot
 * introduce a rounding error, however it is implemented.
 *
 * An empty weight set is a valid answer: it means no instructor earned this
 * period, and the whole amount stays with the platform.
 */
interface RevenueAllocationStrategy
{
    /**
     * Stable identifier, persisted on every allocation row.
     *
     * Stored rather than resolved at read time, so that after the platform
     * switches strategies, a historical split is still explainable by the rule
     * that actually produced it.
     */
    public function name(): string;

    /**
     * @return array<int, int> instructor id => non-negative weight.
     *                         Empty means "nobody earned this period".
     */
    public function weights(EngagementWindow $window): array;
}
