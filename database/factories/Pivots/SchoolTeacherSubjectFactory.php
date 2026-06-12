<?php

declare(strict_types=1);

namespace Database\Factories\Pivots;

use App\Enums\Subject;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\SchoolTeacherSubject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolTeacherSubject>
 */
class SchoolTeacherSubjectFactory extends Factory
{
    protected $model = SchoolTeacherSubject::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_teacher_id' => SchoolTeacher::factory(),
            'subject' => Subject::Chorus,
        ];
    }
}
