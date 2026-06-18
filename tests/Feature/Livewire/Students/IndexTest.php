<?php

declare(strict_types=1);

use App\Livewire\Students\Index;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ClassOfCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeStudentsIndexTeacherUser(): User
{
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    return $user;
}

/**
 * Claims a student for a teacher at a school: enrolls them at the school with the
 * given grade, then links them to the teacher for the given subject.
 */
function claimStudentForTeacher(Teacher $teacher, School $school, string $firstName, string $lastName, int $grade = 9, string $subject = 'band'): StudentTeacher
{
    $student = Student::factory()->create();
    $student->user->update(['first_name' => $firstName, 'last_name' => $lastName]);

    $classOf = ClassOfCalculator::classOfFromGrade($grade, $school->senior_year);
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => $classOf]);

    return StudentTeacher::create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'subject' => $subject,
        'role' => 'primary',
        'is_active' => true,
    ]);
}

test('the students index lists students this teacher teaches', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    claimStudentForTeacher($user->teacher, $school, 'Alice', 'Anderson');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Alice Anderson');
});

test('the students index only lists students linked to this teacher', function () {
    $user = makeStudentsIndexTeacherUser();
    $otherTeacher = Teacher::factory()->create();
    $school = School::factory()->create();

    claimStudentForTeacher($user->teacher, $school, 'Own', 'Student');
    claimStudentForTeacher($otherTeacher, $school, 'Other', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Own Student')
        ->assertDontSee('Other Student');
});

test('students can be searched by name', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();

    claimStudentForTeacher($user->teacher, $school, 'Findme', 'Student');
    claimStudentForTeacher($user->teacher, $school, 'Skip', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Findme')
        ->assertSee('Findme Student')
        ->assertDontSee('Skip Student');
});

test('students can be sorted by name', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();

    claimStudentForTeacher($user->teacher, $school, 'Zeta', 'Student');
    claimStudentForTeacher($user->teacher, $school, 'Alpha', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('sortBy', 'name')
        ->assertSet('sortColumn', 'name')
        ->assertSet('sortDirection', 'desc');
});

test('the students index shows the computed grade for the row\'s school', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();

    claimStudentForTeacher($user->teacher, $school, 'Grade', 'Nine', 9);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('9');
});

test('deactivate sets the student_teacher row is_active to false', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Active', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('deactivate', $row->id);

    expect($row->refresh()->is_active)->toBeFalse();
});

test('deactivate cannot affect a row belonging to another teacher', function () {
    $user = makeStudentsIndexTeacherUser();
    $otherTeacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($otherTeacher, $school, 'Not', 'Mine');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('deactivate', $row->id);

    expect($row->refresh()->is_active)->toBeTrue();
});

test('remove deletes the student_teacher row', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Gone', 'Student');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('remove', $row->id);

    expect(StudentTeacher::find($row->id))->toBeNull();
});

test('edit populates the form with the row\'s subject and role', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me', 9, 'chorus');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->assertSet('edit_subject', 'chorus')
        ->assertSet('edit_role', 'primary');
});

test('saveEdit updates the subject and role on the row', function () {
    $user = makeStudentsIndexTeacherUser();
    $school = School::factory()->create();
    $row = claimStudentForTeacher($user->teacher, $school, 'Edit', 'Me', 9, 'chorus');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('edit', $row->id)
        ->set('edit_subject', 'orchestra')
        ->set('edit_role', 'coteacher')
        ->call('saveEdit')
        ->assertHasNoErrors();

    $row->refresh();
    expect($row->getRawOriginal('subject'))->toBe('orchestra');
    expect($row->getRawOriginal('role'))->toBe('coteacher');
});
