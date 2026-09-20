<?php

declare(strict_types=1);

use App\Actions\Allocation\AllocateSubscriptionPeriod;
use App\Actions\Payouts\FanOutPayoutBatch;
use App\Actions\Refunds\ProcessRefund;
use App\Actions\Refunds\RefundOutcome;
use App\Actions\Refunds\RefundResult;
use App\Domain\Allocation\AllocationStrategyResolver;
use App\Domain\Refunds\RefundKind;
use App\Domain\Subscriptions\Plan;
use App\Models\Allocation;
use App\Models\Course;
use App\Models\Engagement;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use App\Models\Refund;
use App\Models\Student;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * An annual subscription bought on 1 Jan 2026, with two instructors engaged every
 * month, allocated up to and including $throughMonth.
 *
 * @return array{0: Subscription, 1: list<Instructor>}
 */
function annualScenario(int $throughMonth = 4, int $instructors = 2): array
{
    $student = Student::factory()->create();

    $subscription = Subscription::factory()
        ->for($student)
        ->plan(Plan::Annual)
        ->startingOn('2026-01-01')
        ->create();

    Payment::factory()->forSubscription($subscription)->create();

    $created = [];
    $courses = [];

    for ($i = 0; $i < $instructors; $i++) {
        $instructor = Instructor::factory()->create();
        $courses[] = Course::factory()->for($instructor)->create();
        $created[] = $instructor;
    }

    for ($month = 1; $month <= $throughMonth; $month++) {
        $period = sprintf('2026-%02d', $month);

        foreach ($courses as $course) {
            Engagement::factory()->for($student)->forCourse($course)->inPeriod($period)->create();
        }

        app(AllocateSubscriptionPeriod::class)->execute($subscription, $period);
    }

    return [$subscription, $created];
}

function refund(
    Subscription $subscription,
    string $on,
    RefundKind $kind = RefundKind::Prorata,
    string $key = 'refund-key-1',
): RefundResult {
    return app(ProcessRefund::class)->execute(
        subscription: $subscription,
        kind: $kind,
        effectiveOn: CarbonImmutable::parse($on),
        reason: 'Student cancelled mid-term',
        idempotencyKey: $key,
    );
}

/** @return array<int, int> instructor id => earned minor */
function earnedByInstructor(): array
{
    return InstructorBalance::orderBy('instructor_id')->get()
        ->mapWithKeys(fn ($b) => [$b->instructor_id => $b->earned_minor->minor])->all();
}

// ═════════════════════════════════════════════════════════════════════════════
// The headline result
// ═════════════════════════════════════════════════════════════════════════════

it('a clean mid-term refund does not touch any instructor balance', function () {
    // Four months lived and allocated; the student leaves at the end of April.
    // Because revenue is recognised ratably, months 5-12 were never recognised — so
    // there is nothing to take back from anyone.
    [$subscription, $instructors] = annualScenario(throughMonth: 4);

    $before = earnedByInstructor();
    $ledgerBefore = LedgerEntry::count();

    $result = refund($subscription, '2026-04-30');

    expect($result->outcome)->toBe(RefundOutcome::Processed)
        ->and($result->touchedNoInstructor())->toBeTrue()
        ->and($result->allocationsVoided)->toBe(0)
        ->and($result->allocationsAdjusted)->toBe(0)
        ->and(earnedByInstructor())->toBe($before)
        // No correcting entries at all — the ledger is untouched.
        ->and(LedgerEntry::count())->toBe($ledgerBefore);
});

it('refunds the student exactly the unearned portion', function () {
    [$subscription] = annualScenario(throughMonth: 4);

    $result = refund($subscription, '2026-04-30');

    $paid = $subscription->amount_minor->minor;          // 249_900
    $recognised = (int) Allocation::sum('gross_minor');

    // Nothing is created or lost: what was earned plus what is refunded equals what
    // the student paid.
    expect($result->refundedToStudent->minor + $recognised)->toBe($paid)
        ->and($result->refundedToStudent->minor)->toBeGreaterThan(0);
});

it('leaves already-earned months standing', function () {
    [$subscription, $instructors] = annualScenario(throughMonth: 4);

    refund($subscription, '2026-04-30');

    // Every instructor keeps what they earned for the months the student was there.
    foreach ($instructors as $instructor) {
        expect(InstructorBalance::find($instructor->id)->earned_minor->isPositive())->toBeTrue()
            ->and(InstructorBalance::find($instructor->id)->available_minor->isPositive())->toBeTrue();
    }
});

it('stops future months from ever accruing', function () {
    [$subscription] = annualScenario(throughMonth: 4);

    refund($subscription, '2026-04-30');

    // May is now outside the recognised term, so a later allocation run skips it
    // rather than creating earnings for a student who left.
    $result = app(AllocateSubscriptionPeriod::class)
        ->execute($subscription->fresh(), '2026-05');

    expect($result->outcome->value)->toBe('skipped')
        ->and(Allocation::where('accrual_period', '2026-05')->count())->toBe(0);
});

// ═════════════════════════════════════════════════════════════════════════════
// The messy edge: a cancellation inside an already-allocated month
// ═════════════════════════════════════════════════════════════════════════════

it('claws back only the unlived slice of the month the student left in', function () {
    // April is fully allocated, but the student leaves on 15 April. Fifteen of
    // April's thirty days were lived; the rest has to come back.
    [$subscription, $instructors] = annualScenario(throughMonth: 4);

    $aprilBefore = Allocation::where('accrual_period', '2026-04')->sole();
    $earnedBefore = earnedByInstructor();

    $result = refund($subscription, '2026-04-15');

    expect($result->allocationsAdjusted)->toBe(1)
        ->and($result->allocationsVoided)->toBe(0)
        ->and($result->reclaimedFromInstructors->isPositive())->toBeTrue();

    // Each instructor lost something, but strictly less than their whole April line.
    $aprilLines = $aprilBefore->lines->keyBy('instructor_id');

    foreach ($instructors as $instructor) {
        $lost = $earnedBefore[$instructor->id]
            - InstructorBalance::find($instructor->id)->earned_minor->minor;

        expect($lost)->toBeGreaterThan(0)
            ->toBeLessThan($aprilLines[$instructor->id]->amount_minor->minor);
    }

    // Reversals, not clawbacks: this is ordinary pro-rata bookkeeping.
    expect(LedgerEntry::where('type', 'reversal')->count())->toBe(count($instructors))
        ->and(LedgerEntry::where('type', 'clawback')->count())->toBe(0);
});

it('splits a partial reversal in the same proportions as the original split', function () {
    // Watch-time weighting makes the original split uneven, so a proportional
    // correction is visibly different from an equal one.
    config()->set('ledger.allocation_strategy', 'watch_time');
    app()->forgetInstance(AllocationStrategyResolver::class);

    $student = Student::factory()->create();
    $subscription = Subscription::factory()->for($student)
        ->plan(Plan::Monthly)->startingOn('2026-01-01')->create();
    Payment::factory()->forSubscription($subscription)->create();

    $heavy = Instructor::factory()->create();
    $light = Instructor::factory()->create();

    Engagement::factory()->for($student)
        ->forCourse(Course::factory()->for($heavy)->create())
        ->inPeriod('2026-01')->watched(9_000)->create();
    Engagement::factory()->for($student)
        ->forCourse(Course::factory()->for($light)->create())
        ->inPeriod('2026-01')->watched(1_000)->create();

    app(AllocateSubscriptionPeriod::class)->execute($subscription, '2026-01');

    $earnedBefore = earnedByInstructor();

    refund($subscription, '2026-01-15');

    $heavyLost = $earnedBefore[$heavy->id] - InstructorBalance::find($heavy->id)->earned_minor->minor;
    $lightLost = $earnedBefore[$light->id] - InstructorBalance::find($light->id)->earned_minor->minor;

    // 9:1 in, so 9:1 back out. Stated as the largest-remainder property — each
    // party within one minor unit of their exact share — rather than as
    // heavy == 9 x light, which would amplify the light side's rounding ninefold.
    $totalLost = $heavyLost + $lightLost;

    expect($heavyLost)->toBeGreaterThan(0)
        ->and($lightLost)->toBeGreaterThan(0)
        ->and(abs($heavyLost - $totalLost * 0.9))->toBeLessThan(1.0)
        ->and(abs($lightLost - $totalLost * 0.1))->toBeLessThan(1.0);
});

it('voids an allocation that ran ahead of a retroactive cancellation', function () {
    // Someone allocated through April; the cancellation turns out to be 31 January.
    [$subscription, $instructors] = annualScenario(throughMonth: 4);

    $result = refund($subscription, '2026-01-31');

    // February, March and April are gone entirely.
    expect($result->allocationsVoided)->toBe(3)
        ->and($result->allocationsAdjusted)->toBe(0)
        ->and(Allocation::where('status', 'voided')->count())->toBe(3)
        ->and(Allocation::where('status', 'allocated')->count())->toBe(1);

    // January stands, so nobody is left at zero for a month they did teach.
    foreach ($instructors as $instructor) {
        expect(InstructorBalance::find($instructor->id)->earned_minor->isPositive())->toBeTrue();
    }
});

// ═════════════════════════════════════════════════════════════════════════════
// Full refund — the exception, and the clawback path
// ═════════════════════════════════════════════════════════════════════════════

it('a full refund claws back everything and can drive a balance negative', function () {
    [$subscription, $instructors] = annualScenario(throughMonth: 4);

    // Simulate the awkward case: the instructor was already paid for those months.
    $instructor = $instructors[0];
    $earned = InstructorBalance::find($instructor->id)->earned_minor->minor;

    DB::table('instructor_balances')->where('instructor_id', $instructor->id)
        ->update(['paid_minor' => $earned]);

    $result = refund($subscription, '2026-04-30', RefundKind::Full, 'chargeback-1');

    expect($result->outcome)->toBe(RefundOutcome::Processed)
        ->and($result->refundedToStudent->minor)->toBe($subscription->amount_minor->minor)
        ->and($result->allocationsVoided)->toBe(4)
        // Clawback, not reversal: the type keeps "we are recovering paid money"
        // visible in every report.
        ->and(LedgerEntry::where('type', 'clawback')->count())->toBeGreaterThan(0)
        ->and(LedgerEntry::where('type', 'reversal')->count())->toBe(0);

    $balance = InstructorBalance::find($instructor->id);

    // Earned is back to zero while paid stands, so the position is negative — which
    // is the honest representation of "we paid for something that was refunded".
    expect($balance->earned_minor->isZero())->toBeTrue()
        ->and($balance->paid_minor->minor)->toBe($earned)
        ->and($balance->available_minor->isNegative())->toBeTrue();
});

it('a negative balance is never offered to a payout run', function () {
    [$subscription, $instructors] = annualScenario(throughMonth: 4);

    $instructor = $instructors[0];
    DB::table('instructor_balances')->where('instructor_id', $instructor->id)
        ->update(['paid_minor' => InstructorBalance::find($instructor->id)->earned_minor->minor]);

    refund($subscription, '2026-04-30', RefundKind::Full, 'chargeback-2');

    expect(InstructorBalance::find($instructor->id)->available_minor->isNegative())->toBeTrue();

    $batch = PayoutBatch::factory()->forPeriod('2026-05')->create();
    $summary = app(FanOutPayoutBatch::class)->execute($batch);

    // The negative instructor is simply absent. The debt is netted against future
    // earnings, never collected by demanding money back.
    expect(PayoutItem::where('instructor_id', $instructor->id)->count())->toBe(0)
        ->and($summary->itemsCreated)->toBe(0);
});

// ═════════════════════════════════════════════════════════════════════════════
// Idempotency
// ═════════════════════════════════════════════════════════════════════════════

it('a refund webhook delivered twice is processed once', function () {
    [$subscription] = annualScenario(throughMonth: 4);

    $first = refund($subscription, '2026-04-15', key: 'dup-key');
    $second = refund($subscription->fresh(), '2026-04-15', key: 'dup-key');

    expect($first->outcome)->toBe(RefundOutcome::Processed)
        ->and($second->outcome)->toBe(RefundOutcome::AlreadyProcessed)
        ->and($second->refund->id)->toBe($first->refund->id)
        ->and(Refund::count())->toBe(1);
});

it('replaying a refund five times never moves a balance twice', function () {
    [$subscription, $instructors] = annualScenario(throughMonth: 4);

    refund($subscription, '2026-04-15', key: 'replay-key');

    $afterFirst = earnedByInstructor();
    $versions = InstructorBalance::orderBy('instructor_id')->pluck('version', 'instructor_id')->all();

    foreach (range(1, 5) as $ignored) {
        refund($subscription->fresh(), '2026-04-15', key: 'replay-key');
    }

    expect(earnedByInstructor())->toBe($afterFirst)
        // The version counter proves no write happened at all.
        ->and(InstructorBalance::orderBy('instructor_id')->pluck('version', 'instructor_id')->all())
        ->toBe($versions)
        ->and(LedgerEntry::where('type', 'reversal')->count())->toBe(count($instructors));
});

it('the ledger unique index blocks a second reversal of the same line', function () {
    [$subscription] = annualScenario(throughMonth: 4);

    refund($subscription, '2026-04-15', key: 'first');

    $reversals = LedgerEntry::where('type', 'reversal')->count();

    // A different idempotency key gets past the refund guard entirely. The ledger's
    // (type, source_type, source_id) index has to be what stops it.
    refund($subscription->fresh(), '2026-04-15', RefundKind::Prorata, 'second-key');

    expect(LedgerEntry::where('type', 'reversal')->count())->toBe($reversals);
});

// ═════════════════════════════════════════════════════════════════════════════
// The ledger stays verifiable
// ═════════════════════════════════════════════════════════════════════════════

it('leaves the ledger and its snapshots in agreement', function () {
    [$subscription] = annualScenario(throughMonth: 4);

    refund($subscription, '2026-04-15');

    // ledger:verify recomputes every balance from the ledger and every allocation
    // from its lines. If a refund could break the invariant, this is where it shows.
    $this->artisan('ledger:verify')->assertSuccessful();
});

it('keeps the original allocation intact so history stays reproducible', function () {
    [$subscription] = annualScenario(throughMonth: 4);

    $april = Allocation::where('accrual_period', '2026-04')->sole();
    $grossBefore = $april->gross_minor->minor;
    $poolBefore = $april->instructor_pool_minor->minor;

    refund($subscription, '2026-04-15');

    // The allocation records what was allocated; the ledger records what is owed.
    // Editing the original would destroy the audit trail, so the correction is a new
    // entry and this row does not move.
    $april->refresh();

    expect($april->gross_minor->minor)->toBe($grossBefore)
        ->and($april->instructor_pool_minor->minor)->toBe($poolBefore)
        ->and($april->balances())->toBeTrue();
});
