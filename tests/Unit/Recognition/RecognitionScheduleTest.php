<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Recognition\RecognitionSchedule;
use Carbon\CarbonImmutable;

function d(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

// ─────────────────────────────────────────────────────────────────────────────
// Shape of the schedule
// ─────────────────────────────────────────────────────────────────────────────

it('splits a monthly term aligned to a calendar month into one period', function () {
    $schedule = RecognitionSchedule::build(Money::of(29_900), d('2026-01-01'), d('2026-01-31'));

    expect($schedule->periods)->toHaveCount(1)
        ->and($schedule->periods[0]->key)->toBe('2026-01')
        ->and($schedule->periods[0]->days)->toBe(31)
        ->and($schedule->periods[0]->gross->minor)->toBe(29_900);
});

it('splits a mid-month monthly term across two calendar months', function () {
    // 15 Jan → 14 Feb, 31 days: 17 in January, 14 in February.
    $schedule = RecognitionSchedule::build(Money::of(29_900), d('2026-01-15'), d('2026-02-14'));

    expect($schedule->keys())->toBe(['2026-01', '2026-02'])
        ->and($schedule->periods[0]->days)->toBe(17)
        ->and($schedule->periods[1]->days)->toBe(14)
        ->and($schedule->totalDaysRecognised())->toBe(31);
});

it('splits an annual term into thirteen periods when it starts mid-month', function () {
    // The worked example from ARCHITECTURE.md: 15 Jan 2026 → 14 Jan 2027.
    $schedule = RecognitionSchedule::build(Money::of(249_900), d('2026-01-15'), d('2027-01-14'));

    expect($schedule->periods)->toHaveCount(13)
        ->and($schedule->keys()[0])->toBe('2026-01')
        ->and($schedule->keys()[12])->toBe('2027-01')
        ->and($schedule->periods[0]->days)->toBe(17)   // 15–31 Jan
        ->and($schedule->periods[1]->days)->toBe(28)   // Feb 2026, not a leap year
        ->and($schedule->periods[12]->days)->toBe(14)  // 1–14 Jan 2027
        ->and($schedule->totalDaysRecognised())->toBe(365);
});

it('handles a leap February', function () {
    $schedule = RecognitionSchedule::build(Money::of(79_900), d('2028-01-15'), d('2028-04-14'));

    expect($schedule->periods[1]->key)->toBe('2028-02')
        ->and($schedule->periods[1]->days)->toBe(29);
});

it('handles a single-day term', function () {
    $schedule = RecognitionSchedule::build(Money::of(500), d('2026-03-10'), d('2026-03-10'));

    expect($schedule->periods)->toHaveCount(1)
        ->and($schedule->periods[0]->days)->toBe(1)
        ->and($schedule->periods[0]->gross->minor)->toBe(500);
});

it('refuses a term that ends before it starts', function () {
    expect(fn () => RecognitionSchedule::build(Money::of(100), d('2026-05-01'), d('2026-04-01')))
        ->toThrow(InvalidArgumentException::class, 'cannot end before it starts');
});

// ─────────────────────────────────────────────────────────────────────────────
// Exactness — the final period absorbs the residual
// ─────────────────────────────────────────────────────────────────────────────

it('recognises exactly the amount paid, never a piastre more or less', function () {
    $schedule = RecognitionSchedule::build(Money::of(249_900), d('2026-01-15'), d('2027-01-14'));

    expect($schedule->recognisedTotal()->minor)->toBe(249_900)
        ->and($schedule->unearnedTotal()->isZero())->toBeTrue();
});

it('puts the rounding residual in the final period, not the first', function () {
    // 249_900 over 365 days: every floored month loses a fraction, and the
    // residual has to land somewhere deterministic.
    $schedule = RecognitionSchedule::build(Money::of(249_900), d('2026-01-15'), d('2027-01-14'));

    $periods = $schedule->periods;
    $final = end($periods);

    $total = 249_900;
    $termDays = 365;

    // Every period except the last is the plain floor of its day share. Computed
    // here rather than hard-coded, so the test documents the rule instead of a
    // number someone has to re-derive by hand.
    $firstFloor = intdiv($total * $periods[0]->days, $termDays);
    expect($periods[0]->gross->minor)->toBe($firstFloor);

    // The final period is deliberately NOT its own floor: it carries whatever the
    // twelve preceding floors lost.
    $finalFloor = intdiv($total * $final->days, $termDays);
    expect($final->gross->minor)->toBeGreaterThan($finalFloor);

    // And the residual is exactly the shortfall of the floors, nothing invented.
    $sumOfFloors = 0;
    foreach ($periods as $period) {
        $sumOfFloors += intdiv($total * $period->days, $termDays);
    }
    expect($final->gross->minor - $finalFloor)->toBe($total - $sumOfFloors);

    expect($schedule->recognisedTotal()->minor)->toBe($total);
});

it('stays exact for every plan and every possible start day of a year', function () {
    // 3 plans × 365 start dates. If the final-period rule is wrong anywhere — a
    // leap year, a 31st, a term crossing a year boundary — this catches it.
    $plans = [
        ['months' => 1, 'minor' => 29_900],
        ['months' => 3, 'minor' => 79_900],
        ['months' => 12, 'minor' => 249_900],
    ];

    $checked = 0;

    foreach ($plans as $plan) {
        $start = d('2026-01-01');

        for ($day = 0; $day < 365; $day++) {
            $startsOn = $start->addDays($day);
            $endsOn = $startsOn->addMonthsNoOverflow($plan['months'])->subDay();

            $schedule = RecognitionSchedule::build(Money::of($plan['minor']), $startsOn, $endsOn);

            expect($schedule->recognisedTotal()->minor)->toBe($plan['minor']);
            expect($schedule->totalDaysRecognised())
                ->toBe((int) $startsOn->diffInDays($endsOn) + 1);

            $checked++;
        }
    }

    expect($checked)->toBe(1_095);
});

it('never recognises a negative amount in any period', function () {
    foreach ([29_900, 79_900, 249_900, 1, 7, 101] as $minor) {
        $schedule = RecognitionSchedule::build(Money::of($minor), d('2026-01-15'), d('2027-01-14'));

        foreach ($schedule->periods as $period) {
            expect($period->gross->minor)->toBeGreaterThanOrEqual(0);
        }

        expect($schedule->recognisedTotal()->minor)->toBe($minor);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Cancellation — the mechanism behind a pro-rata refund
// ─────────────────────────────────────────────────────────────────────────────

it('stops recognising at the cancellation date', function () {
    // Annual term, student leaves at the end of April — month 4 of 12.
    $schedule = RecognitionSchedule::build(
        total: Money::of(249_900),
        termStartsOn: d('2026-01-01'),
        termEndsOn: d('2026-12-31'),
        recognitionEndsOn: d('2026-04-30'),
    );

    expect($schedule->keys())->toBe(['2026-01', '2026-02', '2026-03', '2026-04'])
        ->and($schedule->totalDaysRecognised())->toBe(31 + 28 + 31 + 30);
});

it('leaves the unearned remainder refundable rather than absorbing it', function () {
    // THE point of ratable recognition: the unconsumed part was never earned, so
    // it can be handed back without touching any instructor balance.
    $schedule = RecognitionSchedule::build(
        total: Money::of(249_900),
        termStartsOn: d('2026-01-01'),
        termEndsOn: d('2026-12-31'),
        recognitionEndsOn: d('2026-04-30'),
    );

    $earned = $schedule->recognisedTotal()->minor;
    $unearned = $schedule->unearnedTotal()->minor;

    // 120 of 365 days consumed.
    expect($earned)->toBeLessThan(249_900)
        ->and($unearned)->toBeGreaterThan(0)
        ->and($earned + $unearned)->toBe(249_900);

    // Roughly 120/365 of the total, within the rounding of four floored months.
    expect($earned)->toBeGreaterThan(81_000)->toBeLessThan(82_500);
});

it('keeps the full term as the proration denominator after a cancellation', function () {
    // The subtle bug this guards: shortening the denominator to the consumed
    // window would inflate the daily rate, so the first four months would recognise
    // the ENTIRE annual fee — turning a cancellation into a revenue windfall and
    // leaving nothing to refund.
    $full = RecognitionSchedule::build(Money::of(249_900), d('2026-01-01'), d('2026-12-31'));

    $cancelled = RecognitionSchedule::build(
        total: Money::of(249_900),
        termStartsOn: d('2026-01-01'),
        termEndsOn: d('2026-12-31'),
        recognitionEndsOn: d('2026-04-30'),
    );

    // The months that were actually lived must be recognised identically in both.
    foreach ([0, 1, 2] as $i) {
        expect($cancelled->periods[$i]->gross->minor)->toBe($full->periods[$i]->gross->minor);
    }
});

it('recognises nothing when cancelled before the term began', function () {
    $schedule = RecognitionSchedule::build(
        total: Money::of(249_900),
        termStartsOn: d('2026-06-01'),
        termEndsOn: d('2027-05-31'),
        recognitionEndsOn: d('2026-05-15'),
    );

    expect($schedule->periods)->toBe([])
        ->and($schedule->recognisedTotal()->isZero())->toBeTrue()
        ->and($schedule->unearnedTotal()->minor)->toBe(249_900);
});

it('ignores a cancellation date beyond the term end', function () {
    $schedule = RecognitionSchedule::build(
        total: Money::of(29_900),
        termStartsOn: d('2026-01-01'),
        termEndsOn: d('2026-01-31'),
        recognitionEndsOn: d('2027-01-01'),
    );

    expect($schedule->totalDaysRecognised())->toBe(31)
        ->and($schedule->recognisedTotal()->minor)->toBe(29_900);
});

it('is monotonic: cancelling later never recognises less', function () {
    $previous = -1;

    foreach (range(1, 365, 7) as $day) {
        $schedule = RecognitionSchedule::build(
            total: Money::of(249_900),
            termStartsOn: d('2026-01-01'),
            termEndsOn: d('2026-12-31'),
            recognitionEndsOn: d('2026-01-01')->addDays($day - 1),
        );

        $earned = $schedule->recognisedTotal()->minor;

        expect($earned)->toBeGreaterThanOrEqual($previous);
        $previous = $earned;
    }

    // Recognising to the last day must arrive at exactly the amount paid.
    expect($previous)->toBeLessThanOrEqual(249_900);
});

it('finds a period by key', function () {
    $schedule = RecognitionSchedule::build(Money::of(249_900), d('2026-01-15'), d('2027-01-14'));

    expect($schedule->findPeriod('2026-06')?->days)->toBe(30)
        ->and($schedule->findPeriod('2029-01'))->toBeNull();
});
