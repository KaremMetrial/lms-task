<?php

declare(strict_types=1);

use App\Domain\Allocation\EngagementWindow;
use App\Domain\Allocation\EqualWeightPerEngagedInstructor;
use App\Domain\Allocation\WeightedByWatchTime;
use App\Domain\Money\Money;

/** Engagement rows as they arrive from the database: one per course. */
function row(int $instructorId, int $watchedSeconds, int $courseId = 0): object
{
    return (object) [
        'instructor_id' => $instructorId,
        'course_id' => $courseId,
        'watched_seconds' => $watchedSeconds,
        'sessions_count' => 1,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// EngagementWindow — aggregation is a correctness requirement, not a convenience
// ─────────────────────────────────────────────────────────────────────────────

it('aggregates several courses by the same instructor into one engagement', function () {
    // The bug this prevents: an instructor who publishes three courses would
    // otherwise receive three equal shares instead of one — a silent overpayment
    // that scales with how many courses they publish.
    $window = EngagementWindow::fromRows([
        row(instructorId: 5, watchedSeconds: 600, courseId: 1),
        row(instructorId: 5, watchedSeconds: 900, courseId: 2),
        row(instructorId: 5, watchedSeconds: 300, courseId: 3),
        row(instructorId: 8, watchedSeconds: 1_200, courseId: 4),
    ]);

    expect($window->count())->toBe(2)
        ->and($window->instructorIds())->toBe([5, 8])
        ->and($window->engagements[5]->watchedSeconds)->toBe(1_800)
        ->and($window->engagements[5]->coursesTouched)->toBe(3)
        ->and($window->totalWatchedSeconds())->toBe(3_000);
});

it('orders instructors by id so downstream tie-breaking is stable', function () {
    $window = EngagementWindow::fromRows([
        row(90, 10), row(3, 10), row(45, 10),
    ]);

    expect($window->instructorIds())->toBe([3, 45, 90]);
});

it('reports an empty window when there was no engagement', function () {
    expect(EngagementWindow::fromRows([])->isEmpty())->toBeTrue()
        ->and(EngagementWindow::empty()->isEmpty())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// EqualWeightPerEngagedInstructor — the default
// ─────────────────────────────────────────────────────────────────────────────

describe('equal weight', function () {
    it('gives every engaged instructor the same weight', function () {
        $weights = (new EqualWeightPerEngagedInstructor)->weights(
            EngagementWindow::fromRows([row(7, 40 * 3_600), row(12, 60), row(31, 900)])
        );

        expect($weights)->toBe([7 => 1, 12 => 1, 31 => 1]);
    });

    it('counts an instructor once regardless of how many courses were watched', function () {
        $weights = (new EqualWeightPerEngagedInstructor)->weights(
            EngagementWindow::fromRows([row(5, 100, 1), row(5, 100, 2), row(9, 100, 3)])
        );

        expect($weights)->toBe([5 => 1, 9 => 1]);
    });

    it('ignores watch time entirely — the accepted unfairness, made explicit', function () {
        // 40 hours of instructor 7 against one minute of instructor 12 still
        // splits 50/50. This is a documented trade-off, so it is asserted rather
        // than left to be discovered.
        $pool = Money::of(20_930);
        $window = EngagementWindow::fromRows([row(7, 144_000), row(12, 60)]);

        $lines = $pool->allocate((new EqualWeightPerEngagedInstructor)->weights($window));

        expect($lines[7]->minor)->toBe(10_465)
            ->and($lines[12]->minor)->toBe(10_465);
    });

    it('produces no weights for an empty window, leaving the period to the platform', function () {
        expect((new EqualWeightPerEngagedInstructor)->weights(EngagementWindow::empty()))->toBe([]);
    });

    it('is named equal_weight', function () {
        expect((new EqualWeightPerEngagedInstructor)->name())->toBe('equal_weight');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// WeightedByWatchTime
// ─────────────────────────────────────────────────────────────────────────────

describe('watch time weighting', function () {
    it('weights strictly by seconds watched', function () {
        $weights = (new WeightedByWatchTime)->weights(
            EngagementWindow::fromRows([row(7, 3_600), row(12, 1_800), row(31, 600)])
        );

        expect($weights)->toBe([7 => 3_600, 12 => 1_800, 31 => 600]);
    });

    it('splits the pool in proportion to attention delivered', function () {
        // 6:3:1 over 20_930 → 12_558 / 6_279 / 2_093, summing exactly.
        $pool = Money::of(20_930);
        $window = EngagementWindow::fromRows([row(7, 6_000), row(12, 3_000), row(31, 1_000)]);

        $lines = $pool->allocate((new WeightedByWatchTime)->weights($window));

        expect($lines[7]->minor)->toBe(12_558)
            ->and($lines[12]->minor)->toBe(6_279)
            ->and($lines[31]->minor)->toBe(2_093)
            ->and(array_sum(array_map(fn ($m) => $m->minor, $lines)))->toBe(20_930);
    });

    it('sums watch time across an instructor\'s courses before weighting', function () {
        // Instructor 5: 600 + 900 = 1_500 against instructor 8's 500 → 3:1.
        $weights = (new WeightedByWatchTime)->weights(
            EngagementWindow::fromRows([row(5, 600, 1), row(5, 900, 2), row(8, 500, 3)])
        );

        expect($weights)->toBe([5 => 1_500, 8 => 500]);
    });

    // ── The two degenerate cases, which are the whole reason this needs tests ──

    it('falls back to equal weight when no watch time was recorded at all', function () {
        // A telemetry outage must not quietly hand the entire period to the
        // platform. The student demonstrably engaged — that is why rows exist —
        // so what is missing is the split, not whether anyone earned.
        $window = EngagementWindow::fromRows([row(7, 0), row(12, 0), row(31, 0)]);

        expect((new WeightedByWatchTime)->weights($window))->toBe([7 => 1, 12 => 1, 31 => 1]);

        $lines = Money::of(20_930)->allocate((new WeightedByWatchTime)->weights($window));
        expect(array_sum(array_map(fn ($m) => $m->minor, $lines)))->toBe(20_930);
    });

    it('gives nothing to an instructor whose course was opened but never watched', function () {
        // The strategy's premise, honestly applied: no attention delivered, no
        // share. This is also precisely why it is not the default.
        $weights = (new WeightedByWatchTime)->weights(
            EngagementWindow::fromRows([row(7, 3_600), row(12, 0)])
        );

        expect($weights)->toBe([7 => 3_600, 12 => 0]);

        $lines = Money::of(20_930)->allocate($weights);
        expect($lines[7]->minor)->toBe(20_930)
            ->and($lines[12]->minor)->toBe(0);
    });

    it('produces no weights for an empty window', function () {
        expect((new WeightedByWatchTime)->weights(EngagementWindow::empty()))->toBe([]);
    });

    it('is named watch_time', function () {
        expect((new WeightedByWatchTime)->name())->toBe('watch_time');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// The property that makes adding strategies safe
// ─────────────────────────────────────────────────────────────────────────────

it('preserves the money invariant for every strategy, over random windows', function () {
    mt_srand(20260920);

    $strategies = [new EqualWeightPerEngagedInstructor, new WeightedByWatchTime];
    $checked = 0;

    for ($i = 0; $i < 600; $i++) {
        $rows = [];
        foreach (range(1, mt_rand(1, 6)) as $n) {
            $rows[] = row(mt_rand(1, 50), mt_rand(0, 2_592_000), $n);
        }

        $window = EngagementWindow::fromRows($rows);
        $pool = Money::of(mt_rand(1, 500_000))->shareOfBps(7_000);

        foreach ($strategies as $strategy) {
            $weights = $strategy->weights($window);

            if ($weights === []) {
                continue;
            }

            $lines = $pool->allocate($weights);

            // Nothing created, nothing destroyed, whichever rule chose the weights.
            expect(array_sum(array_map(fn (Money $m): int => $m->minor, $lines)))
                ->toBe($pool->minor);

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(1_000);
});
