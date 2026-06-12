<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Database\Factories\Pivots\StudentTeacherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['student_id', 'teacher_id', 'school_id', 'subject', 'role', 'is_active'])]
class StudentTeacher extends Pivot
{
    /** @use HasFactory<StudentTeacherFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'student_teacher';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject' => Subject::class,
            'role' => TeacherRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
