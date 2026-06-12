<?php

declare(strict_types=1);

use App\Enums\ShirtSize;
use App\Models\Pivots\SchoolStudent;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

test('student belongs to a user and a user has one student', function () {
    $student = Student::factory()->create();

    expect($student->user)->toBeInstanceOf(User::class);
    expect($student->user->student->id)->toBe($student->id);
});

test('shirt_size casts to ShirtSize enum and defaults to medium', function () {
    $student = Student::factory()->create();

    expect($student->shirt_size)->toBe(ShirtSize::MED);
});

test('instrument and voice_part are nullable', function () {
    $student = Student::factory()->create();

    expect($student->instrument)->toBeNull();
    expect($student->voicePart)->toBeNull();
});

test('getCurrentSchoolAttribute returns the school marked active', function () {
    $student = Student::factory()->create();
    $inactiveSchool = School::factory()->create();
    $activeSchool = School::factory()->create();

    SchoolStudent::factory()->create([
        'student_id' => $student->id,
        'school_id' => $inactiveSchool->id,
        'is_active' => false,
    ]);

    SchoolStudent::factory()->create([
        'student_id' => $student->id,
        'school_id' => $activeSchool->id,
        'is_active' => true,
    ]);

    expect($student->current_school->id)->toBe($activeSchool->id);
});

test('getCurrentSchoolAttribute returns null when no active school', function () {
    $student = Student::factory()->create();

    expect($student->current_school)->toBeNull();
});

test('getGradeAttribute computes grade from class_of and senior_year', function () {
    Carbon::setTestNow(Carbon::create(2026, 3, 1));

    $student = Student::factory()->create();
    $school = School::factory()->create(['school_year' => 'US']);

    SchoolStudent::factory()->create([
        'student_id' => $student->id,
        'school_id' => $school->id,
        'is_active' => true,
        'class_of' => 2028,
    ]);

    expect($student->grade)->toBe(10);
});

test('getGradeAttribute returns null when there is no current school', function () {
    $student = Student::factory()->create();

    expect($student->grade)->toBeNull();
});
