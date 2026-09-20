<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\Instructor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Course> */
final class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory(),
            'title' => ucfirst(fake()->words(3, true)),
        ];
    }
}
