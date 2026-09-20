<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ledger\LedgerEntryType;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LedgerEntry> */
final class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory(),
            'type' => LedgerEntryType::Earning,
            'amount_minor' => 7_000,
            'currency' => config('ledger.currency'),
            'source_type' => 'allocation_line',
            'source_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    public function payout(int $amountMinor = -7_000): static
    {
        return $this->state(fn () => [
            'type' => LedgerEntryType::Payout,
            'amount_minor' => $amountMinor,
            'source_type' => 'payout_item',
        ]);
    }
}
