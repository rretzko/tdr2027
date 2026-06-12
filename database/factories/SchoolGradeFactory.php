<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\School;
use App\Models\SchoolGrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolGrade>
 */
class SchoolGradeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'grade' => fake()->numberBetween(0, 12),
        ];
    }
}
