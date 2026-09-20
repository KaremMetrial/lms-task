<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * The instructors a single student engaged with during one accrual period.
 *
 * Construct it through fromRows(), which aggregates PER INSTRUCTOR.
 *
 * That aggregation is a correctness requirement, not a convenience: engagement is
 * recorded per course, and one instructor can own several courses. A student who
 * watched three courses by the same instructor engaged with ONE instructor. Without
 * aggregating first, equal-weight allocation would hand that instructor three
 * shares instead of one — a silent, systematic overpayment that scales with how
 * many courses an instructor publishes.
 */
final readonly class EngagementWindow
{
    /** @param array<int, InstructorEngagement> $engagements keyed by instructor id */
    private function __construct(public array $engagements) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  iterable<object{instructor_id: int, watched_seconds: int, sessions_count?: int}>  $rows
     */
    public static function fromRows(iterable $rows): self
    {
        /** @var array<int, array{watched: int, sessions: int, courses: int}> $byInstructor */
        $byInstructor = [];

        foreach ($rows as $row) {
            $id = (int) $row->instructor_id;

            $byInstructor[$id] ??= ['watched' => 0, 'sessions' => 0, 'courses' => 0];
            $byInstructor[$id]['watched'] += (int) $row->watched_seconds;
            $byInstructor[$id]['sessions'] += (int) ($row->sessions_count ?? 0);
            $byInstructor[$id]['courses']++;
        }

        ksort($byInstructor);

        $engagements = [];

        foreach ($byInstructor as $id => $totals) {
            $engagements[$id] = new InstructorEngagement(
                instructorId: $id,
                watchedSeconds: $totals['watched'],
                sessionsCount: $totals['sessions'],
                coursesTouched: $totals['courses'],
            );
        }

        return new self($engagements);
    }

    /** @param array<int, InstructorEngagement> $engagements */
    public static function of(array $engagements): self
    {
        $keyed = [];

        foreach ($engagements as $engagement) {
            $keyed[$engagement->instructorId] = $engagement;
        }

        ksort($keyed);

        return new self($keyed);
    }

    public function isEmpty(): bool
    {
        return $this->engagements === [];
    }

    public function count(): int
    {
        return count($this->engagements);
    }

    /** @return list<int> */
    public function instructorIds(): array
    {
        return array_keys($this->engagements);
    }

    public function totalWatchedSeconds(): int
    {
        return array_sum(array_map(
            fn (InstructorEngagement $e): int => $e->watchedSeconds,
            $this->engagements,
        ));
    }
}
