<?php

declare(strict_types=1);

use App\Domain\Allocation\AllocationStrategyResolver;
use App\Domain\Allocation\EqualWeightPerEngagedInstructor;
use App\Domain\Allocation\Exceptions\UnknownAllocationStrategy;
use App\Domain\Allocation\WeightedByWatchTime;

it('defaults to equal weight', function () {
    expect(app(AllocationStrategyResolver::class)->active())
        ->toBeInstanceOf(EqualWeightPerEngagedInstructor::class);
});

it('switches the active strategy from config alone', function () {
    config()->set('ledger.allocation_strategy', WeightedByWatchTime::NAME);
    app()->forgetInstance(AllocationStrategyResolver::class);

    expect(app(AllocationStrategyResolver::class)->active())
        ->toBeInstanceOf(WeightedByWatchTime::class);
});

it('resolves a past allocation by the strategy name it recorded', function () {
    // The point of persisting the strategy name: after the platform switches
    // rules, a historical split must still be re-derivable by the rule that
    // actually produced it.
    $resolver = app(AllocationStrategyResolver::class);

    expect($resolver->byName('equal_weight'))->toBeInstanceOf(EqualWeightPerEngagedInstructor::class)
        ->and($resolver->byName('watch_time'))->toBeInstanceOf(WeightedByWatchTime::class);
});

it('throws on an unregistered strategy instead of falling back to the default', function () {
    // Silently substituting the current default would re-explain an old split
    // with a rule that never touched it — worse than an error.
    expect(fn () => app(AllocationStrategyResolver::class)->byName('by_vibes'))
        ->toThrow(UnknownAllocationStrategy::class, 'by_vibes');
});

it('exposes both registered strategies', function () {
    expect(app(AllocationStrategyResolver::class)->names())
        ->toBe(['equal_weight', 'watch_time']);
});
