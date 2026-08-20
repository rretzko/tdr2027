<?php

declare(strict_types=1);

use App\Models\CoTeacherGrant;
use App\Models\Pivots\SchoolTeacher;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
