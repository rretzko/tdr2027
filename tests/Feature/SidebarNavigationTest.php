<?php

declare(strict_types=1);

use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('a teacher who completed onboarding sees the Schools/Students/Organizations/Events nav links', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school, ['is_active' => true]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Schools')
        ->assertSeeText('Students')
        ->assertSeeText('Organizations')
        ->assertSeeText('Events');
});

test('a student does not see the Schools/Students/Organizations/Events nav links', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Student::factory()->create(['user_id' => $user->id]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Schools')
        ->assertDontSeeText('Organizations')
        ->assertDontSeeText('Events');
});

test('the new nav routes render for a teacher who completed onboarding', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school, ['is_active' => true]);

    actingAs($user)->get(route('schools.index'))->assertOk()->assertSeeText('Schools');
    actingAs($user)->get(route('students.index'))->assertOk()->assertSeeText('Students');
    actingAs($user)->get(route('organizations.index'))->assertOk()->assertSeeText('Organizations');
    actingAs($user)->get(route('events.index'))->assertOk()->assertSeeText('Events');
});

test('the new nav routes redirect a teacher with incomplete onboarding to the wizard', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id]);

    actingAs($user)->get(route('schools.index'))->assertRedirectToRoute('teacher.onboarding');
});

test('a teacher with no active school does not see the Students/Events nav links', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school, ['is_active' => false]);

    // Checked from the Schools page, not the dashboard — the dashboard has its
    // own unrelated "Students"/"Events" card headings that would collide with
    // these assertions regardless of nav-link visibility.
    actingAs($user)->get(route('schools.index'))
        ->assertOk()
        ->assertSeeText('Schools')
        ->assertSeeText('Organizations')
        ->assertDontSeeText('Students')
        ->assertDontSeeText('Events');
});

test('a teacher with an active school sees the Students/Events nav links', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school, ['is_active' => true]);

    actingAs($user)->get(route('schools.index'))
        ->assertOk()
        ->assertSeeText('Students')
        ->assertSeeText('Events');
});

test('visiting Students without an active school redirects to Schools with an explanatory message', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school, ['is_active' => false]);

    actingAs($user)->get(route('students.index'))
        ->assertRedirectToRoute('schools.index');

    actingAs($user)->get(route('schools.index'))
        ->assertSeeText('Add or activate a school here before you can access Students or Events.');
});

test('visiting Events without an active school redirects to Schools', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    actingAs($user)->get(route('events.index'))
        ->assertRedirectToRoute('schools.index');
});

test('a teacher with an active school can visit Students and Events', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school, ['is_active' => true]);

    actingAs($user)->get(route('students.index'))->assertOk();
    actingAs($user)->get(route('events.index'))->assertOk();
});

test('the founder sees the Founder menu', function () {
    // rick@mfrholdings.com may already exist from seeded data — reuse it rather
    // than colliding with the unique email constraint.
    $founder = User::where('email', 'rick@mfrholdings.com')->first()
        ?? User::factory()->create(['email' => 'rick@mfrholdings.com']);

    actingAs($founder)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Founder')
        ->assertSeeText('Impersonate User');
});

test('a non-founder does not see the Founder menu', function () {
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Impersonate User');
});
