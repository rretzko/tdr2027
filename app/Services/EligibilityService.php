<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VersionInvitationStatus;
use App\Models\Candidate;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use Illuminate\Support\Collection;

class EligibilityService
{
    /**
     * Iron gate: a teacher who has rejected this Version's obligations
     * cannot enroll anyone until they accept again (see
     * VersionObligationResponseObserver / CandidateService::withdrawAllForTeacherVersion()).
     */
    public function isBlockedByRejectedObligations(Version $version, Teacher $teacher): bool
    {
        return VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->where('status', VersionInvitationStatus::Rejected->value)
            ->exists();
    }

    /**
     * Returns students the teacher can still enroll in this version:
     * - Active + verified at one of the teacher's active+verified schools
     * - Linked to this teacher (student_teacher.is_active = true)
     * - Not already a candidate for this version
     * - Not blocked by a rejected obligations response (iron gate)
     *
     * Grade filtering is intentionally skipped here: event_grades defines
     * eligible grades but grade is a computed attribute, not a stored column.
     * Event managers control eligibility at the invitation level; this service
     * surfaces everyone the teacher can legally submit.
     *
     * @return Collection<int, Student>
     */
    public function eligibleStudents(Version $version, Teacher $teacher): Collection
    {
        if ($this->isBlockedByRejectedObligations($version, $teacher)) {
            /** @var Collection<int, Student> */
            return collect();
        }

        $schoolIds = $teacher->schools()
            ->wherePivot('is_active', true)
            ->whereNotNull('school_teacher.verified_at')
            ->pluck('schools.id');

        if ($schoolIds->isEmpty()) {
            /** @var Collection<int, Student> */
            return collect();
        }

        $enrolledStudentIds = Candidate::where('version_id', $version->id)
            ->pluck('student_id');

        return Student::query()
            ->whereHas('teachers', function ($q) use ($teacher): void {
                $q->where('teacher_id', $teacher->id)
                    ->where('student_teacher.is_active', true);
            })
            ->whereHas('schools', function ($q) use ($schoolIds): void {
                $q->whereIn('schools.id', $schoolIds)
                    ->where('school_student.is_active', true);
            })
            ->whereNotIn('id', $enrolledStudentIds)
            ->with('user')
            ->orderByRaw('(SELECT last_name FROM users WHERE users.id = students.user_id)')
            ->get();
    }

    /**
     * Returns the school_id to use when enrolling: the teacher's active+verified
     * school that the student is also actively enrolled in. Returns null if no
     * common school is found (enroll() should refuse to proceed).
     */
    public function resolveSchool(Student $student, Teacher $teacher): ?int
    {
        $teacherSchoolIds = $teacher->schools()
            ->wherePivot('is_active', true)
            ->whereNotNull('school_teacher.verified_at')
            ->pluck('schools.id');

        return $student->schools()
            ->wherePivot('is_active', true)
            ->whereIn('schools.id', $teacherSchoolIds)
            ->value('schools.id');
    }
}
