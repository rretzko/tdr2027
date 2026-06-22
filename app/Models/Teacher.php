<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\Pivots\TeacherSupervisor;
use Database\Factories\TeacherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'onboarding_step', 'onboarding_completed_at'])]
class Teacher extends Model
{
    /** @use HasFactory<TeacherFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<School, $this, SchoolTeacher>
     */
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_teacher')
            ->using(SchoolTeacher::class)
            ->withPivot(['role', 'replacing_teacher_name', 'is_active', 'school_email', 'verified_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Student, $this, StudentTeacher>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_teacher')
            ->using(StudentTeacher::class)
            ->withPivot(['school_id', 'subject', 'role', 'is_active'])
            ->withTimestamps();
    }

    public function hasActiveSchool(): bool
    {
        return $this->schools()->wherePivot('is_active', true)->exists();
    }

    /**
     * @return HasMany<TeacherSupervisor, $this>
     */
    public function teacherSupervisors(): HasMany
    {
        return $this->hasMany(TeacherSupervisor::class);
    }

    /**
     * @return HasMany<EventInvitationRequest, $this>
     */
    public function eventInvitationRequests(): HasMany
    {
        return $this->hasMany(EventInvitationRequest::class);
    }
}
