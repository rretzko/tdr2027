<?php

declare(strict_types=1);

use App\Enums\TeacherRole;
use App\Models\Pivots\SchoolTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('the dashboard shows a Schools card with name, role, active state, and verification state', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create(['name' => 'Verify High School']);

    SchoolTeacher::create([
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'role' => TeacherRole::Primary->value,
        'is_active' => true,
        'verified_at' => now(),
    ]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Verify High School')
        ->assertSeeText('Primary')
        ->assertSeeText('Active')
        ->assertSeeText('Email verified');
});

test('the dashboard shows inactive and pending-verification states for a school', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create(['name' => 'Pending Studio']);

    SchoolTeacher::create([
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'role' => TeacherRole::Coteacher->value,
        'is_active' => false,
        'verified_at' => null,
    ]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Pending Studio')
        ->assertSeeText('Co-Teacher')
        ->assertSeeText('Inactive')
        ->assertSeeText('Email pending');
});

test('a student sees no Schools card on the dashboard', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Student::factory()->create(['user_id' => $user->id]);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Schools');
});
