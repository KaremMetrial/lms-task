<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Allocation;
use App\Models\AllocationLine;
use App\Models\Instructor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AllocationLine> */
final class AllocationLineFactory extends Factory
{
    protected $model = AllocationLine::class;

    public function definition(): array
    {
        return [
            'allocation_id' => Allocation::factory(),
            'instructor_id' => Instructor::factory(),
            'amount_minor' => 7_000,
            'currency' => config('ledger.currency'),
            'weight' => 1,
            'weight_total' => 1,
        ];
    }
}
