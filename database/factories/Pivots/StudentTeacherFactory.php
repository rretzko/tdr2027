<?php

declare(strict_types=1);

namespace Database\Factories\Pivots;

use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentTeacher>
 */
class StudentTeacherFactory extends Factory
{
    protected $model = StudentTeacher::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'teacher_id' => Teacher::factory(),
            'school_id' => School::factory(),
            'subject' => Subject::Chorus,
            'role' => TeacherRole::Primary,
            'is_active' => true,
        ];
    }
}
