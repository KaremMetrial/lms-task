<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * One instructor's engagement footprint inside a single accrual period,
 * already aggregated across all of that instructor's courses.
 */
final readonly class InstructorEngagement
{
    public function __construct(
        public int $instructorId,
        public int $watchedSeconds = 0,
        public int $sessionsCount = 0,
        public int $coursesTouched = 1,
    ) {}
}
