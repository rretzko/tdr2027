<?php

declare(strict_types=1);

use App\Livewire\Founder\TeacherVerification;
use App\Mail\SchoolEmailVerificationMail;
use App\Models\CoTeacherGrant;
use App\Models\Pivots\SchoolTeacher;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// makeFounderUser() is already declared globally by ImpersonateTest.php —
// Pest loads every test file into one process for a full-suite run, so
// redeclaring it here would fatal-error the whole suite, not just this file.

test('a non-founder cannot view the teacher verification page', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('founder.teacher-verification'))->assertNotFound();
});

test('resetAllAndSendEmails clears verified_at for every school_teacher row with a school email', function () {
    $founder = makeFounderUser();
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();

    $pivot = SchoolTeacher::factory()->create([
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'school_email' => 'teacher@school.edu',
        'verified_at' => now(),
    ]);

    Mail::fake();

    Livewire::actingAs($founder)
        ->test(TeacherVerification::class)
        ->call('resetAllAndSendEmails');

    expect($pivot->refresh()->verified_at)->toBeNull();
});

test('resetAllAndSendEmails leaves a row with no school_email untouched', function () {
    $founder = makeFounderUser();
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();

    $pivot = SchoolTeacher::factory()->create([
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'school_email' => null,
        'verified_at' => now(),
    ]);

    Mail::fake();

    Livewire::actingAs($founder)
        ->test(TeacherVerification::class)
        ->call('resetAllAndSendEmails');

    expect($pivot->refresh()->verified_at)->not->toBeNull();
});

test('resetAllAndSendEmails queues a verification email to each affected teacher', function () {
    $founder = makeFounderUser();
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();

    SchoolTeacher::factory()->create([
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'school_email' => 'teacher@school.edu',
        'verified_at' => now(),
    ]);

    Mail::fake();

    Livewire::actingAs($founder)
        ->test(TeacherVerification::class)
        ->call('resetAllAndSendEmails');

    Mail::assertQueued(SchoolEmailVerificationMail::class, fn ($mail) => $mail->hasTo('teacher@school.edu'));
});

test('resetAllAndSendEmails goes through Eloquent per row, so it auto-revokes co_teacher_grants at that school', function () {
    $founder = makeFounderUser();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();
    $school = School::factory()->create();

    SchoolTeacher::factory()->create([
        'teacher_id' => $granting->id,
        'school_id' => $school->id,
        'school_email' => 'granting@school.edu',
        'verified_at' => now(),
    ]);

    $grant = CoTeacherGrant::create([
        'school_id' => $school->id,
        'granting_teacher_id' => $granting->id,
        'co_teacher_id' => $coTeacher->id,
        'granted_by_user_id' => $founder->id,
    ]);

    Mail::fake();

    Livewire::actingAs($founder)
        ->test(TeacherVerification::class)
        ->call('resetAllAndSendEmails');

    // This is the regression guard for the bug: a bulk whereNotNull()->update()
    // would silently leave the grant in place, since it skips Eloquent model
    // events entirely (SchoolTeacherObserver never fires) — see
    // docs/plans/co-teacher-definition.md §3.
    expect(CoTeacherGrant::find($grant->id))->toBeNull();
});
