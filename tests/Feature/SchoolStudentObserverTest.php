<?php

declare(strict_types=1);

use App\Enums\Subject;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('activating a school deactivates other active schools for the student', function () {
    $student = Student::factory()->create();
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $pivotA = SchoolStudent::factory()->create([
        'student_id' => $student->id,
        'school_id' => $schoolA->id,
        'is_active' => true,
    ]);

    $pivotB = SchoolStudent::factory()->create([
        'student_id' => $student->id,
        'school_id' => $schoolB->id,
        'is_active' => true,
    ]);

    expect($pivotA->refresh()->is_active)->toBeFalse();
    expect($pivotB->refresh()->is_active)->toBeTrue();
});

test('saving a school_student row cascades is_active to matching student_teacher rows', function () {
    $student = Student::factory()->create();
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();

    StudentTeacher::factory()->create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'subject' => Subject::Chorus,
        'is_active' => true,
    ]);

    SchoolStudent::factory()->create([
        'student_id' => $student->id,
        'school_id' => $school->id,
        'is_active' => false,
    ]);

    $studentTeacher = StudentTeacher::query()
        ->where('student_id', $student->id)
        ->where('school_id', $school->id)
        ->first();

    expect($studentTeacher->is_active)->toBeFalse();
});
