<?php

declare(strict_types=1);

use App\Models\Pivots\SchoolStudent;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeSfdiStudentUser(): User
{
    $user = User::factory()->create();
    Student::factory()->create(['user_id' => $user->id]);

    return $user;
}

test('a student with no active school is redirected to the school page from the dashboard', function () {
    $user = makeSfdiStudentUser();

    actingAs($user)->get(route('dashboard'))->assertRedirectToRoute('sfdi.school');
});

test('a student with an active school can reach the dashboard', function () {
    $user = makeSfdiStudentUser();
    $school = School::factory()->create();
    SchoolStudent::create(['student_id' => $user->student->id, 'school_id' => $school->id, 'is_active' => true, 'class_of' => 2030]);

    actingAs($user)->get(route('dashboard'))->assertOk();
});

test('a teacher (no student profile) is unaffected by the student active-school gate', function () {
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    actingAs($user)->get(route('dashboard'))->assertOk();
});
