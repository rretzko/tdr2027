<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candidate;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Web Registration Manager Module (event-version-orientation.md §5.11):
 * moves a student — and their current-cycle Candidate records — from one
 * teacher/school to another. Generalizes the existing
 * App\Support\ReplacedTeacherStudentTransfer (same-school-only, triggered
 * automatically at teacher-verification time) into an explicit admin action
 * covering all three of the source doc's scenarios: same-school teacher
 * replacement, cross-school transfer, and grade-band promotion.
 */
final class TeacherStudentTransferService
{
    /**
     * Current (still-enrolled) students of $teacher at $school — class_of at
     * or after the school's senior year, same test
     * ReplacedTeacherStudentTransfer already uses. Backs both the
     * checkbox-selectable "Transfer From" roster and the read-only
     * "Current Students" display on the "Transfer To" side.
     *
     * @return Collection<int, Student>
     */
    public function currentStudents(School $school, Teacher $teacher): Collection
    {
        $currentStudentIds = $school->students()
            ->wherePivot('is_active', true)
            ->where('school_student.class_of', '>=', $school->senior_year)
            ->pluck('students.id');

        $activeWithTeacherIds = StudentTeacher::where('school_id', $school->id)
            ->where('teacher_id', $teacher->id)
            ->where('is_active', true)
            ->whereIn('student_id', $currentStudentIds)
            ->pluck('student_id');

        return Student::whereIn('id', $activeWithTeacherIds)
            ->with([
                'user',
                'schools' => fn ($query) => $query->where('schools.id', $school->id),
            ])
            ->get()
            ->sortBy(fn (Student $student): string => $student->user->sort_name)
            ->values();
    }

    /**
     * Moves each of $studentIds from ($fromTeacher, $fromSchool) to
     * ($toTeacher, $toSchool). $studentIds is intersected against
     * currentStudents($fromSchool, $fromTeacher) first, so only students
     * genuinely on that roster can ever be moved regardless of what a
     * caller submits.
     *
     * @param  list<int>  $studentIds
     * @return int number of students actually transferred
     */
    public function transfer(School $fromSchool, Teacher $fromTeacher, School $toSchool, Teacher $toTeacher, array $studentIds): int
    {
        $eligibleStudentIds = $this->currentStudents($fromSchool, $fromTeacher)
            ->pluck('id')
            ->intersect($studentIds)
            ->values();

        DB::transaction(function () use ($fromSchool, $fromTeacher, $toSchool, $toTeacher, $eligibleStudentIds): void {
            foreach ($eligibleStudentIds as $studentId) {
                $this->transferOne((int) $studentId, $fromSchool, $fromTeacher, $toSchool, $toTeacher);
            }
        });

        return $eligibleStudentIds->count();
    }

    private function transferOne(int $studentId, School $fromSchool, Teacher $fromTeacher, School $toSchool, Teacher $toTeacher): void
    {
        if ($toSchool->id !== $fromSchool->id) {
            $classOf = SchoolStudent::where('student_id', $studentId)
                ->where('school_id', $fromSchool->id)
                ->value('class_of');

            // Activating this row cascades the student's other school_student
            // rows to inactive (SchoolStudentObserver::saving()) — a student
            // has exactly one active school, so the old school is
            // deactivated as a side effect, not a separate step here.
            SchoolStudent::updateOrCreate(
                ['student_id' => $studentId, 'school_id' => $toSchool->id],
                ['is_active' => true, 'class_of' => $classOf],
            );
        }

        $rows = StudentTeacher::where('student_id', $studentId)
            ->where('teacher_id', $fromTeacher->id)
            ->where('school_id', $fromSchool->id)
            ->where('is_active', true)
            ->get();

        foreach ($rows as $row) {
            $subject = $row->getRawOriginal('subject');

            $destination = StudentTeacher::where('student_id', $studentId)
                ->where('teacher_id', $toTeacher->id)
                ->where('school_id', $toSchool->id)
                ->where('subject', $subject)
                ->first();

            if ($destination !== null) {
                if (! $destination->is_active) {
                    // Eloquent save (not a raw update()) so
                    // StudentTeacherObserver::updated() fires and
                    // AutoEnrollmentService enrolls the student into any
                    // other open Version the destination teacher is invited to.
                    $destination->update(['is_active' => true]);
                }

                $row->delete();

                continue;
            }

            // A fresh create() (rather than moving $row in place) so
            // StudentTeacherObserver::created() fires the same enrollment
            // cascade — an in-place teacher_id/school_id update wouldn't
            // touch is_active and so wouldn't trigger it.
            StudentTeacher::create([
                'student_id' => $studentId,
                'teacher_id' => $toTeacher->id,
                'school_id' => $toSchool->id,
                'subject' => $subject,
                'role' => $row->getRawOriginal('role'),
                'is_active' => true,
            ]);

            $row->delete();
        }

        Candidate::where('student_id', $studentId)
            ->where('school_id', $fromSchool->id)
            ->where('teacher_id', $fromTeacher->id)
            ->update(['school_id' => $toSchool->id, 'teacher_id' => $toTeacher->id]);
    }
}
