<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Services\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function attachTeacherToSchool(Teacher $teacher, School $school, bool $isActive = true, bool $verified = true): void
{
    $teacher->schools()->attach($school->id, [
        'is_active' => $isActive,
        'verified_at' => $verified ? now() : null,
    ]);
}

function attachStudentToSchool(Student $student, School $school, bool $isActive = true): void
{
    $student->schools()->attach($school->id, ['is_active' => $isActive, 'class_of' => (int) date('Y') + 1]);
}

function linkStudentToTeacher(Student $student, Teacher $teacher, School $school, bool $isActive = true): void
{
    $student->teachers()->attach($teacher->id, [
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => $isActive,
    ]);
}

test('eligibleStudents returns a student linked to the teacher at a shared active, verified school', function () {
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $student = Student::factory()->create();

    attachTeacherToSchool($teacher, $school);
    attachStudentToSchool($student, $school);
    linkStudentToTeacher($student, $teacher, $school);

    $version = Version::factory()->create();

    $result = (new EligibilityService)->eligibleStudents($version, $teacher);

    expect($result->pluck('id'))->toContain($student->id);
});

test('eligibleStudents excludes a student when the teacher has no active, verified school', function () {
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $student = Student::factory()->create();

    attachTeacherToSchool($teacher, $school, isActive: true, verified: false);
    attachStudentToSchool($student, $school);
    linkStudentToTeacher($student, $teacher, $school);

    $version = Version::factory()->create();

    $result = (new EligibilityService)->eligibleStudents($version, $teacher);

    expect($result)->toBeEmpty();
});

test('eligibleStudents excludes a student not linked to the teacher', function () {
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $student = Student::factory()->create();

    attachTeacherToSchool($teacher, $school);
    attachStudentToSchool($student, $school);
    // No student_teacher link created.

    $version = Version::factory()->create();

    $result = (new EligibilityService)->eligibleStudents($version, $teacher);

    expect($result)->toBeEmpty();
});

test('eligibleStudents excludes a student already enrolled as a Candidate for the version', function () {
    actingAs(User::factory()->create());

    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $student = Student::factory()->create();

    attachTeacherToSchool($teacher, $school);
    attachStudentToSchool($student, $school);
    linkStudentToTeacher($student, $teacher, $school);

    $version = Version::factory()->create();

    Candidate::factory()->create([
        'version_id' => $version->id,
        'student_id' => $student->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    $result = (new EligibilityService)->eligibleStudents($version, $teacher);

    expect($result->pluck('id'))->not->toContain($student->id);
});

test('eligibleStudents excludes an inactive student-teacher link', function () {
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $student = Student::factory()->create();

    attachTeacherToSchool($teacher, $school);
    attachStudentToSchool($student, $school);
    linkStudentToTeacher($student, $teacher, $school, isActive: false);

    $version = Version::factory()->create();

    $result = (new EligibilityService)->eligibleStudents($version, $teacher);

    expect($result)->toBeEmpty();
});

test('resolveSchool finds the school shared by the teacher and student', function () {
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $student = Student::factory()->create();

    attachTeacherToSchool($teacher, $school);
    attachStudentToSchool($student, $school);

    $schoolId = (new EligibilityService)->resolveSchool($student, $teacher);

    expect($schoolId)->toBe($school->id);
});

test('resolveSchool returns null when the teacher and student have no shared active school', function () {
    $teacher = Teacher::factory()->create();
    $teacherSchool = School::factory()->create();
    $studentSchool = School::factory()->create();
    $student = Student::factory()->create();

    attachTeacherToSchool($teacher, $teacherSchool);
    attachStudentToSchool($student, $studentSchool);

    $schoolId = (new EligibilityService)->resolveSchool($student, $teacher);

    expect($schoolId)->toBeNull();
});

test('resolveSchool ignores a school where the student is not actively enrolled', function () {
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $student = Student::factory()->create();

    attachTeacherToSchool($teacher, $school);
    attachStudentToSchool($student, $school, isActive: false);

    $schoolId = (new EligibilityService)->resolveSchool($student, $teacher);

    expect($schoolId)->toBeNull();
});
