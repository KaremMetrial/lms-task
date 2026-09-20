<?php

namespace App\Providers;

use App\Domain\Allocation\AllocationStrategyResolver;
use App\Domain\Allocation\RevenueAllocationStrategy;
use App\Domain\Payouts\Provider\MockPaymentProvider;
use App\Domain\Payouts\Provider\OutcomeDecider;
use App\Domain\Payouts\Provider\PaymentProvider;
use App\Domain\Payouts\Provider\WeightedRandomOutcomes;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AllocationStrategyResolver::class, function ($app) {
            /** @var array<string, class-string<RevenueAllocationStrategy>> $strategies */
            $strategies = config('ledger.allocation_strategies');

            return new AllocationStrategyResolver(
                container: $app,
                strategies: $strategies,
                activeName: (string) config('ledger.allocation_strategy'),
            );
        });

        // The provider is bound to an interface so a real one drops in without the
        // payout pipeline changing. Tests swap the decider for a scripted sequence,
        // never the provider itself — so the code under test is the real code.
        $this->app->bind(OutcomeDecider::class, fn () => new WeightedRandomOutcomes(
            (array) config('ledger.mock_provider.outcomes'),
        ));

        $this->app->bind(PaymentProvider::class, fn ($app) => new MockPaymentProvider(
            $app->make(OutcomeDecider::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
