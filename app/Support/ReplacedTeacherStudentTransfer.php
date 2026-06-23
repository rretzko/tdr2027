<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;

final class ReplacedTeacherStudentTransfer
{
    /**
     * Moves the replaced teacher's current students at this school over to the
     * newly-verified teacher. "Current" means still enrolled — class_of at or
     * after the school's senior year — so alumni rosters aren't disturbed.
     *
     * If the new teacher already has a student_teacher row for the same student/
     * subject (e.g. they already co-taught), the replaced teacher's row is
     * dropped instead of moved, since moving it would violate the table's unique
     * (student_id, teacher_id, school_id, subject) constraint.
     *
     * @return int<0, max> number of students transferred
     */
    public static function transfer(SchoolTeacher $schoolTeacher): int
    {
        if (blank($schoolTeacher->replacing_teacher_name)) {
            return 0;
        }

        $school = $schoolTeacher->school;

        $replacedTeacher = $school->teachers()
            ->where('teachers.id', '!=', $schoolTeacher->teacher_id)
            ->whereHas('user', fn ($query) => $query->where('name', $schoolTeacher->replacing_teacher_name))
            ->first();

        if ($replacedTeacher === null) {
            return 0;
        }

        $currentStudentIds = $school->students()
            ->where('school_student.class_of', '>=', $school->senior_year)
            ->pluck('students.id');

        $rows = StudentTeacher::where('school_id', $school->id)
            ->where('teacher_id', $replacedTeacher->id)
            ->whereIn('student_id', $currentStudentIds)
            ->get();

        $transferredStudentIds = [];

        foreach ($rows as $row) {
            $newTeacherAlreadyHasRow = StudentTeacher::where('student_id', $row->student_id)
                ->where('teacher_id', $schoolTeacher->teacher_id)
                ->where('school_id', $row->school_id)
                ->where('subject', $row->getRawOriginal('subject'))
                ->exists();

            if ($newTeacherAlreadyHasRow) {
                $row->delete();

                continue;
            }

            $row->update(['teacher_id' => $schoolTeacher->teacher_id]);
            $transferredStudentIds[$row->student_id] = true;
        }

        return count($transferredStudentIds);
    }
}
