<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Pivots\SchoolStudent;
use App\Models\School;
use App\Models\Teacher;
use Illuminate\Support\Collection;

final class TeacherRosterSummary
{
    /**
     * Total student count and a grade-level breakdown for each school the teacher
     * is linked to, so the dashboard can show roster counts separated by school.
     *
     * @return Collection<int, array{school: School, total: int<0, max>, byGrade: array<int, int<1, max>>}>
     */
    public static function forTeacher(Teacher $teacher): Collection
    {
        return $teacher->schools->map(function (School $school) use ($teacher) {
            $students = $teacher->students()
                ->wherePivot('school_id', $school->id)
                ->get()
                ->unique('id');

            $classOfByStudentId = SchoolStudent::where('school_id', $school->id)
                ->whereIn('student_id', $students->pluck('id'))
                ->pluck('class_of', 'student_id');

            $byGrade = [];

            foreach ($classOfByStudentId as $classOf) {
                $grade = ClassOfCalculator::gradeFromClassOf((int) $classOf, $school->senior_year);
                $byGrade[$grade] = ($byGrade[$grade] ?? 0) + 1;
            }

            ksort($byGrade);

            return [
                'school' => $school,
                'total' => $students->count(),
                'byGrade' => $byGrade,
            ];
        });
    }
}
