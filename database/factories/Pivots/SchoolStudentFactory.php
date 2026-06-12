<?php

declare(strict_types=1);

namespace Database\Factories\Pivots;

use App\Models\Pivots\SchoolStudent;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolStudent>
 */
class SchoolStudentFactory extends Factory
{
    protected $model = SchoolStudent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'school_id' => School::factory(),
            'is_active' => true,
            'class_of' => now()->year + 1,
        ];
    }
}
