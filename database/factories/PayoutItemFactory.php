<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payouts\PayoutItemStatus;
use App\Models\Instructor;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayoutItem> */
final class PayoutItemFactory extends Factory
{
    protected $model = PayoutItem::class;

    public function definition(): array
    {
        return [
            'payout_batch_id' => PayoutBatch::factory(),
            'instructor_id' => Instructor::factory(),
            'amount_minor' => 50_000,
            'currency' => config('ledger.currency'),
            'status' => PayoutItemStatus::Pending,

            // Derived from (batch, instructor) exactly as production does, so tests
            // exercise the real key rather than a stand-in.
            'idempotency_key' => fn (array $attributes) => PayoutItem::idempotencyKeyFor(
                (int) $attributes['payout_batch_id'],
                (int) $attributes['instructor_id'],
            ),
            'attempts' => 0,
        ];
    }

    public function status(PayoutItemStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
