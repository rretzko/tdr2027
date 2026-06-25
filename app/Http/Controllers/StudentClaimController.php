<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ClaimStatus;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StudentClaimController extends Controller
{
    /**
     * Approves every pending subject row from one claim request at once (a
     * request can cover several subjects, each its own student_teacher row)
     * and creates the school_student enrollment the pending rows couldn't —
     * see the migration note on pending_class_of for why that's deferred
     * until now rather than created at request time.
     */
    public function approve(Student $student, Teacher $teacher, School $school): View
    {
        $pendingRows = $this->pendingRows($student, $teacher, $school);

        if ($pendingRows->isEmpty()) {
            throw new NotFoundHttpException;
        }

        $classOf = $pendingRows->first()->pending_class_of;

        SchoolStudent::firstOrCreate(
            ['student_id' => $student->id, 'school_id' => $school->id],
            ['is_active' => true, 'class_of' => $classOf]
        );

        foreach ($pendingRows as $row) {
            $row->update([
                'claim_status' => ClaimStatus::Approved->value,
                'is_active' => true,
                'pending_class_of' => null,
            ]);
        }

        return view('student-claim.approved', ['student' => $student, 'school' => $school]);
    }

    public function deny(Student $student, Teacher $teacher, School $school): View
    {
        $pendingRows = $this->pendingRows($student, $teacher, $school);

        if ($pendingRows->isEmpty()) {
            throw new NotFoundHttpException;
        }

        $pendingRows->each(fn (StudentTeacher $row) => $row->delete());

        return view('student-claim.denied', ['student' => $student, 'school' => $school]);
    }

    /**
     * @return Collection<int, StudentTeacher>
     */
    private function pendingRows(Student $student, Teacher $teacher, School $school): Collection
    {
        return StudentTeacher::where('student_id', $student->id)
            ->where('teacher_id', $teacher->id)
            ->where('school_id', $school->id)
            ->where('claim_status', ClaimStatus::Pending->value)
            ->get();
    }
}
