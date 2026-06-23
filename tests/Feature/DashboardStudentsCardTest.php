<?php

declare(strict_types=1);

use App\Enums\TeacherRole;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ClassOfCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function linkSchool(Teacher $teacher, School $school, bool $isActive = true): void
{
    SchoolTeacher::create([
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'role' => TeacherRole::Primary->value,
        'is_active' => $isActive,
    ]);
}

function claimStudent(Teacher $teacher, School $school, int $grade, string $subject = 'band'): Student
{
    $student = Student::factory()->create();
    $classOf = ClassOfCalculator::classOfFromGrade($grade, $school->senior_year);

    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $classOf]);

    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'subject' => $subject,
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    return $student;
}

test('the dashboard shows a Students card with total and grade breakdown for a single school', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create(['name' => 'Verify High School']);
    linkSchool($teacher, $school);

    claimStudent($teacher, $school, 9);
    claimStudent($teacher, $school, 9);
    claimStudent($teacher, $school, 10);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Students')
        ->assertSeeText('3 students')
        ->assertSeeText('Grade 9: 2')
        ->assertSeeText('Grade 10: 1');
});

test('the dashboard separates the Students card by school when the teacher has multiple', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $schoolA = School::factory()->create(['name' => 'School A']);
    $schoolB = School::factory()->create(['name' => 'School B']);
    linkSchool($teacher, $schoolA);
    linkSchool($teacher, $schoolB);

    claimStudent($teacher, $schoolA, 11);
    claimStudent($teacher, $schoolA, 11);
    claimStudent($teacher, $schoolB, 7);

    // "2 students" belongs to School A's section, appearing before School B's heading.
    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeTextInOrder(['School A', '2 students', 'School B', '1 student'])
        ->assertSeeText('Grade 11: 2')
        ->assertSeeText('Grade 7: 1');
});

test('a student claimed for two subjects with the same teacher is not double-counted', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    linkSchool($teacher, $school);

    $student = claimStudent($teacher, $school, 9, 'band');
    StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
    ]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('1 student')
        ->assertSeeText('Grade 9: 1')
        ->assertDontSeeText('2 students');
});

test('the Students card omits schools the teacher has marked inactive', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $activeSchool = School::factory()->create(['name' => 'Active School']);
    $inactiveSchool = School::factory()->create(['name' => 'Inactive School']);
    linkSchool($teacher, $activeSchool);
    linkSchool($teacher, $inactiveSchool, isActive: false);

    claimStudent($teacher, $activeSchool, 9);
    claimStudent($teacher, $inactiveSchool, 10);

    // The Schools card (unaffected by this change) still lists both schools by
    // name, so the inactive school's exclusion is checked via its roster data
    // (grade breakdown / count) rather than its name, which appears elsewhere.
    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('1 student')
        ->assertSeeText('Grade 9: 1')
        ->assertDontSeeText('Grade 10: 1');
});

test('the Students card shows a no-students message for a school with no roster yet', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    linkSchool($teacher, $school);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('No students yet.');
});
