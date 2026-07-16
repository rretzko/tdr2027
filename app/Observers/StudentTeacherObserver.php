<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pivots\StudentTeacher;
use App\Services\AutoEnrollmentService;

/**
 * Proactively enrolls a newly-rostered (or reactivated) student into every
 * Version the teacher currently participates in, rather than waiting for a
 * manual "Enroll a Student" action. Covers direct creation/reactivation of
 * a student_teacher row (Students\Index, TeacherOnboardingWizard,
 * StudentClaimController::approve()) — and, since SchoolStudentObserver was
 * updated to save() each pivot individually instead of a bulk query-builder
 * update(), the indirect is_active cascade triggered by a student's school
 * reactivating fires updated() here too.
 */
class StudentTeacherObserver
{
    public function created(StudentTeacher $pivot): void
    {
        if ($pivot->is_active) {
            $this->enroll($pivot);
        }
    }

    public function updated(StudentTeacher $pivot): void
    {
        if ($pivot->is_active && $pivot->wasChanged('is_active')) {
            $this->enroll($pivot);
        }
    }

    private function enroll(StudentTeacher $pivot): void
    {
        app(AutoEnrollmentService::class)->enrollNewStudentIntoInvitedActiveVersions($pivot->student, $pivot->teacher);
    }
}
