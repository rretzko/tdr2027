<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AbandonedStudentCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** A teacher-created placeholder student: no password, unverified, enrolled at a school, rostered nowhere. */
function abandonedCleanupShellStudent(): Student
{
    $user = User::factory()->create(['password' => null, 'email_verified_at' => null]);
    $student = Student::factory()->create(['user_id' => $user->id]);
    School::factory()->create()->students()->attach($student->id, ['is_active' => true, 'class_of' => 2031]);

    return $student;
}

function abandonedCleanupService(): AbandonedStudentCleanupService
{
    return app(AbandonedStudentCleanupService::class);
}

test('a never-used, unrostered student is deleted along with its user, school and eligible candidate', function () {
    $student = abandonedCleanupShellStudent();
    $candidate = Candidate::factory()->create(['student_id' => $student->id]);
    EmergencyContact::factory()->create(['student_id' => $student->id]);
    $userId = $student->user_id;

    expect(abandonedCleanupService()->deleteIfAbandoned($student))->toBeTrue()
        ->and(Student::find($student->id))->toBeNull()
        ->and(User::find($userId))->toBeNull()
        ->and(Candidate::find($candidate->id))->toBeNull()
        ->and(DB::table('school_student')->where('student_id', $student->id)->exists())->toBeFalse()
        ->and(DB::table('emergency_contacts')->where('student_id', $student->id)->exists())->toBeFalse();
});

test('a student still on any roster is kept', function () {
    $student = abandonedCleanupShellStudent();
    $teacher = Teacher::factory()->create();
    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'school_id' => School::factory()->create()->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => false,
    ]);

    expect(abandonedCleanupService()->deleteIfAbandoned($student))->toBeFalse()
        ->and(Student::find($student->id))->not->toBeNull();
});

test('a student whose account has a password or a verified email is kept', function (array $userAttributes) {
    $student = abandonedCleanupShellStudent();
    $student->user->forceFill($userAttributes)->save();

    expect(abandonedCleanupService()->deleteIfAbandoned($student))->toBeFalse()
        ->and(Student::find($student->id))->not->toBeNull();
})->with([
    'password set' => [['password' => 'secret-password']],
    'email verified' => [['email_verified_at' => now()]],
]);

test('a student who has ever signed in is kept', function () {
    $student = abandonedCleanupShellStudent();
    DB::table('login_events')->insert(['user_id' => $student->user_id, 'method' => 'password', 'created_at' => now()]);

    expect(abandonedCleanupService()->deleteIfAbandoned($student))->toBeFalse();
});

test('a student whose candidate has moved past eligible is kept', function () {
    $student = abandonedCleanupShellStudent();
    Candidate::factory()->create(['student_id' => $student->id, 'status' => CandidateStatus::Registered]);

    expect(abandonedCleanupService()->deleteIfAbandoned($student))->toBeFalse()
        ->and(Student::find($student->id))->not->toBeNull();
});

test('a student whose candidate has a signed application is kept', function () {
    $student = abandonedCleanupShellStudent();
    Candidate::factory()->create(['student_id' => $student->id, 'application_parent_signed_at' => now()]);

    expect(abandonedCleanupService()->deleteIfAbandoned($student))->toBeFalse();
});

test('a user with a teacher record is never deleted', function () {
    $student = abandonedCleanupShellStudent();
    Teacher::factory()->create(['user_id' => $student->user_id]);

    expect(abandonedCleanupService()->deleteIfAbandoned($student))->toBeFalse()
        ->and(User::find($student->user_id))->not->toBeNull();
});
