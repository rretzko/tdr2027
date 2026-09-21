<?php

declare(strict_types=1);

use App\Livewire\Founder\AddTeacher;
use App\Mail\SchoolEmailVerificationMail;
use App\Models\County;
use App\Models\Geostate;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pronoun;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// makeFounderUser() is already declared globally by ImpersonateTest.php —
// Pest loads every test file into one process for a full-suite run, so
// redeclaring it here would fatal-error the whole suite, not just this file.

test('a non-founder cannot view the add teacher page', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('founder.add-teacher'))->assertNotFound();
});

test('the founder can view the add teacher page', function () {
    $founder = makeFounderUser();

    actingAs($founder)->get(route('founder.add-teacher'))->assertOk();
});

test('createTeacher requires a selected school', function () {
    $founder = makeFounderUser();
    $pronoun = Pronoun::factory()->create();

    Livewire::actingAs($founder)
        ->test(AddTeacher::class)
        ->set('first_name', 'Jamie')
        ->set('last_name', 'Rivera')
        ->set('pronoun_id', (string) $pronoun->id)
        ->set('email', 'jamie.rivera@example.com')
        ->call('createTeacher')
        ->assertHasErrors(['selectedSchoolId']);

    expect(User::where('email', 'jamie.rivera@example.com')->exists())->toBeFalse();
});

test('createTeacher creates a user, a teacher profile, the Teacher role, and a school link', function () {
    $founder = makeFounderUser();
    $pronoun = Pronoun::factory()->create();
    $school = School::factory()->create();

    Livewire::actingAs($founder)
        ->test(AddTeacher::class)
        ->set('first_name', 'Jamie')
        ->set('last_name', 'Rivera')
        ->set('pronoun_id', (string) $pronoun->id)
        ->set('email', 'jamie.rivera@example.com')
        ->set('school_email', 'jrivera@school.edu')
        ->call('selectSchool', $school->id)
        ->call('createTeacher');

    $user = User::where('email', 'jamie.rivera@example.com')->firstOrFail();

    expect($user->teacher)->not->toBeNull()
        ->and($user->hasRole('Teacher'))->toBeTrue();

    $pivot = SchoolTeacher::where('teacher_id', $user->teacher->id)->where('school_id', $school->id)->first();

    expect($pivot)->not->toBeNull()
        ->and($pivot->is_active)->toBeTrue()
        ->and($pivot->school_email)->toBe('jrivera@school.edu')
        ->and($pivot->verified_at)->toBeNull();
});

test('createSchool creates a new school and selects it', function () {
    $founder = makeFounderUser();
    $pronoun = Pronoun::factory()->create();
    $geostate = Geostate::factory()->create();
    $county = County::factory()->create(['geostate_id' => $geostate->id]);

    Livewire::actingAs($founder)
        ->test(AddTeacher::class)
        ->set('first_name', 'Jamie')
        ->set('last_name', 'Rivera')
        ->set('pronoun_id', (string) $pronoun->id)
        ->set('email', 'jamie.rivera@example.com')
        ->set('geostate_id', (string) $geostate->id)
        ->set('new_school_name', 'New Studio')
        ->set('new_school_type', 'studio')
        ->set('new_school_city', 'Trenton')
        ->set('new_school_zip_code', '08601')
        ->set('new_school_county_id', (string) $county->id)
        ->call('createSchool')
        ->call('createTeacher');

    $school = School::where('name', 'New Studio')->firstOrFail();
    $user = User::where('email', 'jamie.rivera@example.com')->firstOrFail();

    expect(SchoolTeacher::where('teacher_id', $user->teacher->id)->where('school_id', $school->id)->exists())->toBeTrue();
});

test('verifyUserEmailNow marks the created user verified', function () {
    $founder = makeFounderUser();
    $pronoun = Pronoun::factory()->create();
    $school = School::factory()->create();

    $component = Livewire::actingAs($founder)
        ->test(AddTeacher::class)
        ->set('first_name', 'Jamie')
        ->set('last_name', 'Rivera')
        ->set('pronoun_id', (string) $pronoun->id)
        ->set('email', 'jamie.rivera@example.com')
        ->call('selectSchool', $school->id)
        ->call('createTeacher')
        ->call('verifyUserEmailNow');

    $user = User::where('email', 'jamie.rivera@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('sendUserVerificationEmail sends the verification notification', function () {
    Notification::fake();

    $founder = makeFounderUser();
    $pronoun = Pronoun::factory()->create();
    $school = School::factory()->create();

    Livewire::actingAs($founder)
        ->test(AddTeacher::class)
        ->set('first_name', 'Jamie')
        ->set('last_name', 'Rivera')
        ->set('pronoun_id', (string) $pronoun->id)
        ->set('email', 'jamie.rivera@example.com')
        ->call('selectSchool', $school->id)
        ->call('createTeacher')
        ->call('sendUserVerificationEmail');

    $user = User::where('email', 'jamie.rivera@example.com')->firstOrFail();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('verifySchoolEmailNow marks the school email verified', function () {
    $founder = makeFounderUser();
    $pronoun = Pronoun::factory()->create();
    $school = School::factory()->create();

    $component = Livewire::actingAs($founder)
        ->test(AddTeacher::class)
        ->set('first_name', 'Jamie')
        ->set('last_name', 'Rivera')
        ->set('pronoun_id', (string) $pronoun->id)
        ->set('email', 'jamie.rivera@example.com')
        ->set('school_email', 'jrivera@school.edu')
        ->call('selectSchool', $school->id)
        ->call('createTeacher')
        ->call('verifySchoolEmailNow');

    $pivot = SchoolTeacher::where('school_email', 'jrivera@school.edu')->firstOrFail();

    expect($pivot->verified_at)->not->toBeNull();
});

test('sendSchoolVerificationEmail sends the school verification mail', function () {
    Mail::fake();

    $founder = makeFounderUser();
    $pronoun = Pronoun::factory()->create();
    $school = School::factory()->create();

    Livewire::actingAs($founder)
        ->test(AddTeacher::class)
        ->set('first_name', 'Jamie')
        ->set('last_name', 'Rivera')
        ->set('pronoun_id', (string) $pronoun->id)
        ->set('email', 'jamie.rivera@example.com')
        ->set('school_email', 'jrivera@school.edu')
        ->call('selectSchool', $school->id)
        ->call('createTeacher')
        ->call('sendSchoolVerificationEmail');

    Mail::assertSent(SchoolEmailVerificationMail::class, fn ($mail) => $mail->hasTo('jrivera@school.edu'));
});
