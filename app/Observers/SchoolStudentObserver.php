<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\StudentTeacher;

class SchoolStudentObserver
{
    public function saving(SchoolStudent $schoolStudent): void
    {
        if ($schoolStudent->is_active && $schoolStudent->isDirty('is_active')) {
            SchoolStudent::query()
                ->where('student_id', $schoolStudent->student_id)
                ->when(
                    $schoolStudent->exists,
                    fn ($query) => $query->whereKeyNot($schoolStudent->getKey()),
                )
                ->update(['is_active' => false]);
        }
    }

    /**
     * Goes through each pivot model's own save() rather than a single bulk
     * query-builder update() — a raw update() would never fire Eloquent
     * events, and StudentTeacherObserver relies on updated() (is_active
     * flipping to true) to trigger auto-enrollment (§6.2) for a student
     * whose school access was reactivated.
     */
    public function saved(SchoolStudent $schoolStudent): void
    {
        StudentTeacher::query()
            ->where('student_id', $schoolStudent->student_id)
            ->where('school_id', $schoolStudent->school_id)
            ->get()
            ->each(fn (StudentTeacher $pivot) => $pivot->update(['is_active' => $schoolStudent->is_active]));
    }
}
