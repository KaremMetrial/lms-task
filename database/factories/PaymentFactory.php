<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),

            // The whole term is paid up front on day one, so both the amount and the
            // payment date come from the subscription rather than being invented.
            'amount_minor' => fn (array $attributes) => $this->subscriptionFor($attributes)->amount_minor->minor,
            'currency' => config('ledger.currency'),
            'paid_at' => fn (array $attributes) => $this->subscriptionFor($attributes)->starts_on->startOfDay(),

            'idempotency_key' => hash('sha256', 'payment:'.Str::uuid()->toString()),
            'external_reference' => 'pay_'.fake()->bothify('??????####'),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function subscriptionFor(array $attributes): Subscription
    {
        return Subscription::query()->findOrFail($attributes['subscription_id']);
    }

    /** The whole term paid up front on day one, which is the real flow. */
    public function forSubscription(Subscription $subscription): static
    {
        return $this->state(fn () => [
            'subscription_id' => $subscription->id,
            'amount_minor' => $subscription->amount_minor->minor,
            'currency' => $subscription->currency,
            'paid_at' => $subscription->starts_on->startOfDay(),
            'idempotency_key' => hash('sha256', "payment:subscription:{$subscription->id}"),
        ]);
    }
}
