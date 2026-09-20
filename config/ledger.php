<?php

declare(strict_types=1);

use App\Domain\Allocation\EqualWeightPerEngagedInstructor;
use App\Domain\Allocation\WeightedByWatchTime;
use App\Domain\Payouts\Provider\MockOutcome;

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Single currency by design. Money refuses cross-currency arithmetic, so
    | introducing a second one is a deliberate project, not a config change.
    |
    */
    'currency' => env('LEDGER_CURRENCY', 'EGP'),

    /*
    |--------------------------------------------------------------------------
    | Platform share
    |--------------------------------------------------------------------------
    |
    | In basis points. 3000 = 30% to the platform, 70% to instructors.
    |
    | READ ONLY AT PURCHASE TIME. This value is snapshotted onto
    | subscriptions.platform_share_bps, and allocation reads the subscription,
    | never this file. Changing it here affects new subscriptions only — it must
    | never retroactively rewrite what instructors already earned.
    |
    */
    'platform_share_bps' => (int) env('LEDGER_PLATFORM_SHARE_BPS', 3000),

    /*
    |--------------------------------------------------------------------------
    | Allocation strategy
    |--------------------------------------------------------------------------
    |
    | Which rule decides how one period's instructor pool is divided.
    |
    | The active name is snapshotted onto every allocation row, so switching this
    | does not make past splits unexplainable — each allocation still records the
    | rule that produced it.
    |
    */
    'allocation_strategy' => env('LEDGER_ALLOCATION_STRATEGY', EqualWeightPerEngagedInstructor::NAME),

    'allocation_strategies' => [
        EqualWeightPerEngagedInstructor::NAME => EqualWeightPerEngagedInstructor::class,
        WeightedByWatchTime::NAME => WeightedByWatchTime::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | Paid in full on day one, recognised straight-line across the term.
    | Amounts in minor units (piastres).
    |
    */
    'plans' => [
        'monthly' => ['months' => 1, 'amount_minor' => 29_900],
        'quarterly' => ['months' => 3, 'amount_minor' => 79_900],
        'annual' => ['months' => 12, 'amount_minor' => 249_900],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    */
    'payout' => [

        // Below this, a payout is skipped and carried forward. Provider fees make
        // dust payouts value-destroying for both sides. The money stays visible as
        // outstanding and joins the next run.
        'minimum_minor' => (int) env('LEDGER_MINIMUM_PAYOUT_MINOR', 10_000),

        // An item left in 'submitted' for longer than this is swept to 'unknown'.
        // Not to 'failed' — the provider may already have moved the money, and
        // assuming failure is exactly how you pay twice.
        'stale_submitted_after_seconds' => 900,

        // Backoff between status checks on an 'unknown' item. The retry is a
        // STATUS CHECK, never a resend.
        'status_check_backoff_seconds' => [30, 120, 600, 1_800, 3_600],

        // After this many inconclusive checks the item is parked for a human.
        // Guessing after N attempts would defeat the entire design.
        'max_status_checks' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scale seeding
    |--------------------------------------------------------------------------
    |
    | Read here rather than with env() inside the seeder. env() outside a config
    | file returns null once `config:cache` has run, so a cached deployment would
    | silently fall back to the small defaults — and a scale test that quietly
    | seeded 20k rows instead of 500k proves nothing.
    |
    */
    'seeding' => [
        'subscriptions' => (int) env('SEED_SUBSCRIPTIONS', 20_000),
        'instructors' => (int) env('SEED_INSTRUCTORS', 2_000),
        'courses_per_instructor' => (int) env('SEED_COURSES_PER_INSTRUCTOR', 3),
        'periods' => (int) env('SEED_PERIODS', 12),
        'instructors_per_student' => (int) env('SEED_INSTRUCTORS_PER_STUDENT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mock provider
    |--------------------------------------------------------------------------
    |
    | Outcome weights for the fake provider. Tests override these with a seeded,
    | deterministic sequence so every branch is exercised on purpose.
    |
    */
    'mock_provider' => [

        // Keyed by the enum's own values rather than by hand-written strings.
        //
        // The first version of this file used 'succeeded' and 'failed', which do not
        // exist on MockOutcome — so the very first real run threw on
        // MockOutcome::from(). Deriving the keys means the two can no longer drift.
        'outcomes' => [
            MockOutcome::Succeed->value => 60,
            MockOutcome::FailPermanently->value => 10,
            MockOutcome::TimeoutAfterSuccess->value => 20,
            MockOutcome::TimeoutBeforeAnything->value => 10,
        ],
    ],
];
