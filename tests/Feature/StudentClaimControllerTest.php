<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ClaimStatus;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ClassOfCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL as UrlFacade;
use Tests\TestCase;

class StudentClaimControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeacherUser(): User
    {
        $user = User::factory()->create();
        Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

        return $user;
    }

    private function pendingClaimRow(User $requestingUser, School $school, int $studentId, int $classOf): StudentTeacher
    {
        return StudentTeacher::create([
            'student_id' => $studentId,
            'teacher_id' => $requestingUser->teacher->id,
            'school_id' => $school->id,
            'subject' => 'chorus',
            'role' => 'primary',
            'is_active' => false,
            'claim_status' => ClaimStatus::Pending->value,
            'pending_class_of' => $classOf,
        ]);
    }

    public function test_approve_activates_rows_and_creates_school_student_enrollment(): void
    {
        $requestingUser = $this->makeTeacherUser();
        $studio = School::factory()->create();
        $student = Student::factory()->create();
        $student->user->update(['first_name' => 'Wendel', 'last_name' => 'Quoxbury']);
        $classOf = ClassOfCalculator::classOfFromGrade(10, $studio->senior_year);
        $this->pendingClaimRow($requestingUser, $studio, $student->id, $classOf);

        $approveUrl = UrlFacade::temporarySignedRoute('student-claim.approve', now()->addDays(7), [
            'student' => $student->id,
            'teacher' => $requestingUser->teacher->id,
            'school' => $studio->id,
        ]);

        $this->get($approveUrl)->assertOk()->assertSeeText('Request approved');

        $row = StudentTeacher::where('student_id', $student->id)
            ->where('teacher_id', $requestingUser->teacher->id)
            ->where('school_id', $studio->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(ClaimStatus::Approved->value, $row->getRawOriginal('claim_status'));
        $this->assertTrue($row->is_active);
        $this->assertNull($row->pending_class_of);

        $schoolStudent = SchoolStudent::where('student_id', $student->id)
            ->where('school_id', $studio->id)
            ->first();

        $this->assertNotNull($schoolStudent);
        $this->assertSame($classOf, (int) $schoolStudent->class_of);
    }

    public function test_approve_is_idempotent_when_already_approved(): void
    {
        $requestingUser = $this->makeTeacherUser();
        $studio = School::factory()->create();
        $student = Student::factory()->create();
        $classOf = ClassOfCalculator::classOfFromGrade(9, $studio->senior_year);
        $this->pendingClaimRow($requestingUser, $studio, $student->id, $classOf);

        $approveUrl = UrlFacade::temporarySignedRoute('student-claim.approve', now()->addDays(7), [
            'student' => $student->id,
            'teacher' => $requestingUser->teacher->id,
            'school' => $studio->id,
        ]);

        $this->get($approveUrl)->assertOk();
        $this->get($approveUrl)->assertNotFound();
    }

    public function test_deny_deletes_pending_rows(): void
    {
        $requestingUser = $this->makeTeacherUser();
        $studio = School::factory()->create();
        $student = Student::factory()->create();
        $pendingRow = $this->pendingClaimRow($requestingUser, $studio, $student->id, 2026);

        $denyUrl = UrlFacade::temporarySignedRoute('student-claim.deny', now()->addDays(7), [
            'student' => $student->id,
            'teacher' => $requestingUser->teacher->id,
            'school' => $studio->id,
        ]);

        $this->get($denyUrl)->assertOk()->assertSeeText('Request denied');

        $this->assertNull(StudentTeacher::find($pendingRow->id));
        $this->assertFalse(
            SchoolStudent::where('student_id', $student->id)->where('school_id', $studio->id)->exists()
        );
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $requestingUser = $this->makeTeacherUser();
        $studio = School::factory()->create();
        $student = Student::factory()->create();

        $validUrl = UrlFacade::temporarySignedRoute('student-claim.approve', now()->addDays(7), [
            'student' => $student->id,
            'teacher' => $requestingUser->teacher->id,
            'school' => $studio->id,
        ]);

        $tampered = $validUrl.'X';

        $this->get($tampered)->assertForbidden();
    }
}
