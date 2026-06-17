<?php

declare(strict_types=1);

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('a teacher who completed onboarding sees the Schools/Students/Organizations/Events nav links', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

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
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

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
