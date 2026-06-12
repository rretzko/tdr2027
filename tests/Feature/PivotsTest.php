<?php

declare(strict_types=1);

use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\Pivots\TeacherSupervisor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('school_student enforces unique student_id + school_id', function () {
    $pivot = SchoolStudent::factory()->create();

    expect(fn () => SchoolStudent::factory()->create([
        'student_id' => $pivot->student_id,
        'school_id' => $pivot->school_id,
    ]))->toThrow(QueryException::class);
});

test('school_teacher enforces unique school_id + teacher_id', function () {
    $pivot = SchoolTeacher::factory()->create();

    expect(fn () => SchoolTeacher::factory()->create([
        'school_id' => $pivot->school_id,
        'teacher_id' => $pivot->teacher_id,
    ]))->toThrow(QueryException::class);
});

test('teacher_supervisors enforces unique organization_id + teacher_id', function () {
    $pivot = TeacherSupervisor::factory()->create();

    expect(fn () => TeacherSupervisor::factory()->create([
        'organization_id' => $pivot->organization_id,
        'teacher_id' => $pivot->teacher_id,
    ]))->toThrow(QueryException::class);
});

test('student_teacher casts subject and role to enums', function () {
    $pivot = StudentTeacher::factory()->create([
        'subject' => Subject::Band,
        'role' => TeacherRole::Coteacher,
    ]);

    $pivot->refresh();

    expect($pivot->subject)->toBe(Subject::Band);
    expect($pivot->role)->toBe(TeacherRole::Coteacher);
    expect($pivot->is_active)->toBeTrue();
});
