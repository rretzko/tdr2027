<?php

declare(strict_types=1);

use App\Enums\TeacherRole;
use App\Models\CoTeacherGrant;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ClassOfCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A replaced teacher at $school with one current student, plus the new
 * teacher's (unverified) replacement link naming them.
 *
 * @return array{replaced: Teacher, newLink: SchoolTeacher, student: Student}
 */
function replacementScenarioForObserver(School $school, array $newLinkAttributes = []): array
{
    $replaced = Teacher::factory()->create(['user_id' => User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Former'])->id]);
    SchoolTeacher::create(['school_id' => $school->id, 'teacher_id' => $replaced->id, 'role' => TeacherRole::Primary->value, 'is_active' => false]);

    $student = Student::factory()->create();
    $school->students()->attach($student->id, ['is_active' => true, 'class_of' => ClassOfCalculator::classOfFromGrade(11, $school->senior_year)]);
    StudentTeacher::create([
        'student_id' => $student->id, 'teacher_id' => $replaced->id, 'school_id' => $school->id,
        'subject' => 'chorus', 'role' => TeacherRole::Primary->value, 'is_active' => true,
    ]);

    $newLink = SchoolTeacher::create([
        'school_id' => $school->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
        'school_email' => 'new@school.edu',
        'replaced_teacher_id' => $replaced->id,
        ...$newLinkAttributes,
    ]);

    return ['replaced' => $replaced, 'newLink' => $newLink, 'student' => $student];
}

function makeCoTeacherGrant(School $school, Teacher $granting, Teacher $coTeacher): CoTeacherGrant
{
    return CoTeacherGrant::create([
        'school_id' => $school->id,
        'granting_teacher_id' => $granting->id,
        'co_teacher_id' => $coTeacher->id,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

test('deactivating the granting teacher\'s school link deletes their outgoing grants at that school', function () {
    $school = School::factory()->create();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();

    $pivot = SchoolTeacher::factory()->create([
        'school_id' => $school->id,
        'teacher_id' => $granting->id,
        'is_active' => true,
        'verified_at' => now(),
    ]);

    $grant = makeCoTeacherGrant($school, $granting, $coTeacher);

    $pivot->update(['is_active' => false]);

    expect(CoTeacherGrant::find($grant->id))->toBeNull();
});

test('deactivating the recipient co-teacher\'s school link deletes their incoming grants at that school', function () {
    $school = School::factory()->create();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();

    $pivot = SchoolTeacher::factory()->create([
        'school_id' => $school->id,
        'teacher_id' => $coTeacher->id,
        'is_active' => true,
        'verified_at' => now(),
    ]);

    $grant = makeCoTeacherGrant($school, $granting, $coTeacher);

    $pivot->update(['is_active' => false]);

    expect(CoTeacherGrant::find($grant->id))->toBeNull();
});

test('unverifying a school link (verified_at cleared) also revokes grants at that school', function () {
    $school = School::factory()->create();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();

    $pivot = SchoolTeacher::factory()->create([
        'school_id' => $school->id,
        'teacher_id' => $granting->id,
        'is_active' => true,
        'verified_at' => now(),
    ]);

    $grant = makeCoTeacherGrant($school, $granting, $coTeacher);

    $pivot->update(['verified_at' => null]);

    expect(CoTeacherGrant::find($grant->id))->toBeNull();
});

test('deactivating a school link at an unrelated school does not touch a grant elsewhere', function () {
    $grantSchool = School::factory()->create();
    $otherSchool = School::factory()->create();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();

    $otherPivot = SchoolTeacher::factory()->create([
        'school_id' => $otherSchool->id,
        'teacher_id' => $granting->id,
        'is_active' => true,
        'verified_at' => now(),
    ]);

    $grant = makeCoTeacherGrant($grantSchool, $granting, $coTeacher);

    $otherPivot->update(['is_active' => false]);

    expect(CoTeacherGrant::find($grant->id))->not->toBeNull();
});

test('re-activating a school link does not auto-restore a previously revoked grant', function () {
    $school = School::factory()->create();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();

    $pivot = SchoolTeacher::factory()->create([
        'school_id' => $school->id,
        'teacher_id' => $granting->id,
        'is_active' => true,
        'verified_at' => now(),
    ]);

    $grant = makeCoTeacherGrant($school, $granting, $coTeacher);

    $pivot->update(['is_active' => false]);
    $pivot->update(['is_active' => true]);

    expect(CoTeacherGrant::find($grant->id))->toBeNull();
});

test('a save with no is_active/verified_at change leaves grants untouched', function () {
    $school = School::factory()->create();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();

    $pivot = SchoolTeacher::factory()->create([
        'school_id' => $school->id,
        'teacher_id' => $granting->id,
        'is_active' => true,
        'verified_at' => now(),
    ]);

    $grant = makeCoTeacherGrant($school, $granting, $coTeacher);

    $pivot->update(['school_email' => 'someone@example.com']);

    expect(CoTeacherGrant::find($grant->id))->not->toBeNull();
});

test('verifying an inactive school link reactivates it', function () {
    $pivot = SchoolTeacher::factory()->create([
        'school_email' => 'teacher@school.edu',
        'is_active' => false,
        'verified_at' => null,
    ]);

    $pivot->update(['verified_at' => now()]);

    expect($pivot->refresh()->is_active)->toBeTrue();
});

test('clearing verification on an active link does not reactivate anything or flip is_active', function () {
    $pivot = SchoolTeacher::factory()->create([
        'is_active' => true,
        'verified_at' => now(),
    ]);

    $pivot->update(['verified_at' => null, 'is_active' => false]);

    expect($pivot->refresh()->is_active)->toBeFalse();
});

test('updating an already-verified inactive link without touching verified_at leaves it inactive', function () {
    $pivot = SchoolTeacher::factory()->create([
        'is_active' => false,
        'verified_at' => now(),
    ]);

    $pivot->update(['role' => $pivot->role]);
    $pivot->update(['replacing_teacher_name' => 'Someone']);

    expect($pivot->refresh()->is_active)->toBeFalse();
});

test('verifying a replacement link by any update transfers the replaced teacher\'s current students', function () {
    $school = School::factory()->create();
    ['newLink' => $newLink, 'student' => $student] = replacementScenarioForObserver($school);

    expect(StudentTeacher::where('student_id', $student->id)->value('teacher_id'))->not->toBe($newLink->teacher_id);

    // The path Schools\Index auto-verify, Founder\AddTeacher and the email link all share.
    $newLink->update(['verified_at' => now()]);

    expect($newLink->replacementTransferCount)->toBe(1);
    expect(StudentTeacher::where('student_id', $student->id)->value('teacher_id'))->toBe($newLink->teacher_id);
    expect($newLink->refresh()->replacement_transferred_at)->not->toBeNull();
});

test('creating an already-verified replacement link transfers the replaced teacher\'s current students', function () {
    $school = School::factory()->create();
    ['newLink' => $newLink, 'student' => $student] = replacementScenarioForObserver($school, ['verified_at' => now()]);

    expect($newLink->replacementTransferCount)->toBe(1);
    expect(StudentTeacher::where('student_id', $student->id)->value('teacher_id'))->toBe($newLink->teacher_id);
});

test('an unverified replacement link does not transfer students', function () {
    $school = School::factory()->create();
    ['replaced' => $replaced, 'newLink' => $newLink, 'student' => $student] = replacementScenarioForObserver($school);

    $newLink->update(['role' => TeacherRole::Coteacher->value]);

    expect(StudentTeacher::where('student_id', $student->id)->value('teacher_id'))->toBe($replaced->id);
    expect($newLink->refresh()->replacement_transferred_at)->toBeNull();
});

test('re-verifying after the annual reset does not re-run a completed transfer', function () {
    $school = School::factory()->create();
    ['replaced' => $replaced, 'newLink' => $newLink] = replacementScenarioForObserver($school, ['verified_at' => now()]);

    // The replaced teacher later gains a student here (e.g. they came back).
    $later = Student::factory()->create();
    $school->students()->attach($later->id, ['is_active' => true, 'class_of' => ClassOfCalculator::classOfFromGrade(10, $school->senior_year)]);
    StudentTeacher::create([
        'student_id' => $later->id, 'teacher_id' => $replaced->id, 'school_id' => $school->id,
        'subject' => 'chorus', 'role' => TeacherRole::Primary->value, 'is_active' => true,
    ]);

    $newLink->update(['verified_at' => null, 'is_active' => false]);
    $newLink->update(['verified_at' => now()]);

    expect($newLink->replacementTransferCount)->toBe(0);
    expect(StudentTeacher::where('student_id', $later->id)->value('teacher_id'))->toBe($replaced->id);
});

test('picking a different replaced teacher on a verified link runs the transfer for them', function () {
    $school = School::factory()->create();
    ['newLink' => $newLink] = replacementScenarioForObserver($school, ['verified_at' => now()]);

    $other = Teacher::factory()->create();
    SchoolTeacher::create(['school_id' => $school->id, 'teacher_id' => $other->id, 'role' => TeacherRole::Primary->value, 'is_active' => false]);
    $otherStudent = Student::factory()->create();
    $school->students()->attach($otherStudent->id, ['is_active' => true, 'class_of' => ClassOfCalculator::classOfFromGrade(11, $school->senior_year)]);
    StudentTeacher::create([
        'student_id' => $otherStudent->id, 'teacher_id' => $other->id, 'school_id' => $school->id,
        'subject' => 'chorus', 'role' => TeacherRole::Primary->value, 'is_active' => true,
    ]);

    $newLink->update(['replaced_teacher_id' => $other->id]);

    expect($newLink->replacementTransferCount)->toBe(1);
    expect(StudentTeacher::where('student_id', $otherStudent->id)->value('teacher_id'))->toBe($newLink->teacher_id);
});

test('verifying an inactive replacement link reactivates it and transfers', function () {
    $school = School::factory()->create();
    ['newLink' => $newLink, 'student' => $student] = replacementScenarioForObserver($school, ['is_active' => false]);

    // updating() reactivates on verification, so the transfer sees an active link.
    $newLink->update(['verified_at' => now()]);

    expect($newLink->refresh()->is_active)->toBeTrue();
    expect(StudentTeacher::where('student_id', $student->id)->value('teacher_id'))->toBe($newLink->teacher_id);
});
