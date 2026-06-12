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

    public function saved(SchoolStudent $schoolStudent): void
    {
        StudentTeacher::query()
            ->where('student_id', $schoolStudent->student_id)
            ->where('school_id', $schoolStudent->school_id)
            ->update(['is_active' => $schoolStudent->is_active]);
    }
}
