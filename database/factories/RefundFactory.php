<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Refunds\RefundKind;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Refund> */
final class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        $subscription = Subscription::factory()->create();
        $payment = Payment::factory()->forSubscription($subscription)->create();

        return [
            'subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
            'amount_minor' => 10_000,
            'currency' => config('ledger.currency'),
            'kind' => RefundKind::Prorata,
            'reason' => 'Student cancelled mid-term',
            'refunded_at' => now(),
            'idempotency_key' => hash('sha256', 'refund:'.Str::uuid()->toString()),
        ];
    }

    public function full(): static
    {
        return $this->state(fn () => [
            'kind' => RefundKind::Full,
            'reason' => 'Chargeback',
        ]);
    }
}
