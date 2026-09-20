<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Instructor;
use App\Models\InstructorBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InstructorBalance> */
final class InstructorBalanceFactory extends Factory
{
    protected $model = InstructorBalance::class;

    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory(),
            'currency' => config('ledger.currency'),
            'earned_minor' => 0,
            'paid_minor' => 0,
            'reserved_minor' => 0,
            'version' => 0,
        ];
    }

    public function owed(int $minor): static
    {
        return $this->state(fn () => ['earned_minor' => $minor, 'version' => 1]);
    }
}
