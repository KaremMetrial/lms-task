<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Allocation\AllocationStatus;
use App\Domain\Allocation\EqualWeightPerEngagedInstructor;
use App\Models\Allocation;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Allocation> */
final class AllocationFactory extends Factory
{
    protected $model = Allocation::class;

    public function definition(): array
    {
        $subscription = Subscription::factory()->create();
        $payment = Payment::factory()->forSubscription($subscription)->create();

        $period = $subscription->starts_on->format('Y-m');
        $start = $subscription->starts_on;
        $end = $start->endOfMonth()->startOfDay();

        $gross = 10_000;
        $platform = intdiv($gross * $subscription->platform_share_bps, 10_000);

        return [
            'subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
            'accrual_period' => $period,
            'period_starts_on' => $start,
            'period_ends_on' => $end,
            'days_in_period' => (int) $start->diffInDays($end) + 1,
            'gross_minor' => $gross,
            'platform_minor' => $platform,
            'instructor_pool_minor' => $gross - $platform,
            'currency' => config('ledger.currency'),
            'strategy' => EqualWeightPerEngagedInstructor::NAME,
            'status' => AllocationStatus::Allocated,
            'allocated_at' => CarbonImmutable::now(),
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'status' => AllocationStatus::Voided,
            'voided_at' => CarbonImmutable::now(),
        ]);
    }
}
