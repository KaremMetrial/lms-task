<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\Engagement;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Engagement> */
final class EngagementFactory extends Factory
{
    protected $model = Engagement::class;

    public function definition(): array
    {
        $course = Course::factory()->create();

        return [
            'student_id' => Student::factory(),
            'course_id' => $course->id,

            // Kept consistent with the course on purpose: the denormalised column
            // must reflect who actually taught it.
            'instructor_id' => $course->instructor_id,

            'accrual_period' => now()->format('Y-m'),
            'watched_seconds' => fake()->numberBetween(60, 20 * 3_600),
            'sessions_count' => fake()->numberBetween(1, 30),
            'last_engaged_at' => now(),
        ];
    }

    public function forCourse(Course $course): static
    {
        return $this->state(fn () => [
            'course_id' => $course->id,
            'instructor_id' => $course->instructor_id,
        ]);
    }

    public function inPeriod(string $period): static
    {
        return $this->state(fn () => ['accrual_period' => $period]);
    }

    public function watched(int $seconds): static
    {
        return $this->state(fn () => ['watched_seconds' => $seconds]);
    }

    /** Opened, never actually watched — the zero-weight case. */
    public function opened(): static
    {
        return $this->state(fn () => ['watched_seconds' => 0, 'sessions_count' => 1]);
    }
}
