<?php

declare(strict_types=1);

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('creating a teacher clears a previously set email_unverifiable flag', function () {
    $user = User::factory()->unverifiableEmail()->create(['email' => 'former.student@rocktwp.net']);

    Teacher::create(['user_id' => $user->id]);

    expect($user->fresh()->email_unverifiable)->toBeFalse();
});

test('a teacher user cannot be flagged email_unverifiable later', function () {
    $user = User::factory()->create(['email' => 'teacher@rocktwp.net']);
    Teacher::create(['user_id' => $user->id]);

    $user->forceFill(['email_unverifiable' => true])->save();

    expect($user->fresh()->email_unverifiable)->toBeFalse();
});

test('non-teacher users can still be flagged email_unverifiable', function () {
    $user = User::factory()->create(['email' => 'student@rocktwp.net']);
    Student::create(['user_id' => $user->id]);

    $user->forceFill(['email_unverifiable' => true])->save();

    expect($user->fresh()->email_unverifiable)->toBeTrue();
});

test('the backfill command flags matching students but skips teachers', function () {
    $student = User::factory()->create(['email' => 'kid@rocktwp.net']);
    Student::create(['user_id' => $student->id]);

    $teacher = User::factory()->create(['email' => 'director@rocktwp.net']);
    Teacher::create(['user_id' => $teacher->id]);

    expect(Artisan::call('users:backfill-email-unverifiable', ['--force' => true]))->toBe(0);

    expect($student->fresh()->email_unverifiable)->toBeTrue()
        ->and($teacher->fresh()->email_unverifiable)->toBeFalse();
});
