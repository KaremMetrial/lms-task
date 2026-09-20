<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payouts\PayoutBatchStatus;
use App\Models\PayoutBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayoutBatch> */
final class PayoutBatchFactory extends Factory
{
    protected $model = PayoutBatch::class;

    public function definition(): array
    {
        return [
            'period_key' => now()->format('Y-m'),
            'status' => PayoutBatchStatus::Pending,
            'currency' => config('ledger.currency'),
            'minimum_payout_minor' => config('ledger.payout.minimum_minor'),
            'items_count' => 0,
            'total_minor' => 0,
        ];
    }

    public function forPeriod(string $periodKey): static
    {
        return $this->state(fn () => ['period_key' => $periodKey]);
    }
}
