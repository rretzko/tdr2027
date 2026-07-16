<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Enums\ClaimStatus;
use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Observers\StudentTeacherObserver;
use Database\Factories\Pivots\StudentTeacherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['student_id', 'teacher_id', 'school_id', 'subject', 'role', 'is_active', 'claim_status', 'pending_class_of'])]
#[ObservedBy(StudentTeacherObserver::class)]
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
            'claim_status' => ClaimStatus::class,
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

    /**
     * A cross-org claim awaiting approval from the student's existing
     * teacher(s) — mirrors SchoolTeacher::isPending(). Until approved, the
     * requesting teacher hasn't been granted access to the existing
     * student's full profile (see Students\Index::edit()'s guard).
     */
    public function isPending(): bool
    {
        return $this->getRawOriginal('claim_status') === ClaimStatus::Pending->value;
    }
}
