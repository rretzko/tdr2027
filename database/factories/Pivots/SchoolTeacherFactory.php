<?php

declare(strict_types=1);

namespace Database\Factories\Pivots;

use App\Models\Pivots\SchoolTeacher;
use App\Models\School;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolTeacher>
 */
class SchoolTeacherFactory extends Factory
{
    protected $model = SchoolTeacher::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'teacher_id' => Teacher::factory(),
            'is_active' => true,
            'school_email' => fake()->unique()->safeEmail(),
            'verified_at' => null,
        ];
    }
}
