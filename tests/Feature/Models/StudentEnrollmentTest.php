<?php

declare(strict_types=1);

use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('isCurrentlyEnrolled is true with an active school link whose class_of has not yet reached the senior year', function () {
    $student = Student::factory()->create();
    $school = School::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => $school->senior_year]);

    expect($student->isCurrentlyEnrolled())->toBeTrue();
});

test('isCurrentlyEnrolled is false with no school links at all', function () {
    $student = Student::factory()->create();

    expect($student->isCurrentlyEnrolled())->toBeFalse();
});

test('isCurrentlyEnrolled is false when the only school link has already graduated (class_of behind the senior year)', function () {
    $student = Student::factory()->create();
    $school = School::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => $school->senior_year - 1]);

    expect($student->isCurrentlyEnrolled())->toBeFalse();
});

test('isCurrentlyEnrolled is false when the only qualifying class_of is on an inactive school link', function () {
    $student = Student::factory()->create();
    $school = School::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => false, 'class_of' => $school->senior_year]);

    expect($student->isCurrentlyEnrolled())->toBeFalse();
});
