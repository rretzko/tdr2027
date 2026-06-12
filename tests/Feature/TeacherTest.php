<?php

declare(strict_types=1);

use App\Enums\Subject;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\SchoolTeacherSubject;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('teacher belongs to a user and a user has one teacher', function () {
    $teacher = Teacher::factory()->create();

    expect($teacher->user)->toBeInstanceOf(User::class);
    expect($teacher->user->teacher->id)->toBe($teacher->id);
});

test('teacher schools pivot exposes is_active, school_email, and verified_at', function () {
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();
    $verifiedAt = now();

    SchoolTeacher::factory()->create([
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
        'school_email' => 'teacher@school.edu',
        'verified_at' => $verifiedAt,
    ]);

    $pivot = $teacher->schools()->first()->pivot;

    expect($pivot->is_active)->toBeTrue();
    expect($pivot->school_email)->toBe('teacher@school.edu');
    expect($pivot->verified_at)->not->toBeNull();
});

test('a school_teacher can have multiple subject rows', function () {
    $schoolTeacher = SchoolTeacher::factory()->create();

    SchoolTeacherSubject::factory()->create([
        'school_teacher_id' => $schoolTeacher->id,
        'subject' => Subject::Band,
    ]);

    SchoolTeacherSubject::factory()->create([
        'school_teacher_id' => $schoolTeacher->id,
        'subject' => Subject::Chorus,
    ]);

    expect($schoolTeacher->subjects)->toHaveCount(2);
});
