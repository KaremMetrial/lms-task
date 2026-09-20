<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Subscriptions\Plan;
use App\Domain\Subscriptions\SubscriptionStatus;
use App\Models\Student;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $plan = fake()->randomElement(Plan::cases());
        $startsOn = CarbonImmutable::parse(fake()->dateTimeBetween('-18 months', 'now'))->startOfDay();

        return [
            'student_id' => Student::factory(),
            'plan' => $plan,
            'amount_minor' => $plan->price()->minor,
            'currency' => config('ledger.currency'),

            // Snapshotted at purchase time, exactly as the real flow does — so a
            // factory-built subscription exercises the same code path.
            'platform_share_bps' => config('ledger.platform_share_bps'),

            'starts_on' => $startsOn,
            'ends_on' => $plan->termEndFor($startsOn),
            'status' => SubscriptionStatus::Active,
            'cancelled_on' => null,
        ];
    }

    public function plan(Plan $plan, ?CarbonImmutable $startsOn = null): static
    {
        return $this->state(function (array $attributes) use ($plan, $startsOn) {
            $start = $startsOn ?? CarbonImmutable::parse($attributes['starts_on']);

            return [
                'plan' => $plan,
                'amount_minor' => $plan->price()->minor,
                'starts_on' => $start,
                'ends_on' => $plan->termEndFor($start),
            ];
        });
    }

    public function startingOn(string|CarbonImmutable $date): static
    {
        return $this->state(function (array $attributes) use ($date) {
            $start = CarbonImmutable::parse($date)->startOfDay();
            $plan = $attributes['plan'] instanceof Plan
                ? $attributes['plan']
                : Plan::from($attributes['plan']);

            return ['starts_on' => $start, 'ends_on' => $plan->termEndFor($start)];
        });
    }

    public function cancelledOn(string|CarbonImmutable $date): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_on' => CarbonImmutable::parse($date)->startOfDay(),
        ]);
    }

    public function platformShareBps(int $bps): static
    {
        return $this->state(fn () => ['platform_share_bps' => $bps]);
    }
}
