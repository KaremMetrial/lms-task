<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Instructor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Instructor> */
final class InstructorFactory extends Factory
{
    protected $model = Instructor::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'payout_account_ref' => 'acct_'.fake()->unique()->bothify('??????##'),
            'status' => 'active',
        ];
    }

    /** Earns normally, but cannot be paid yet: earnings accrue, payouts wait. */
    public function withoutPayoutAccount(): static
    {
        return $this->state(fn () => ['payout_account_ref' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
