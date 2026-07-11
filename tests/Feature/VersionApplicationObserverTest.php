<?php

declare(strict_types=1);

use App\Models\Version;
use App\Models\VersionApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('VersionApplicationObserver sanitizes all three bodies on direct model creation', function () {
    $version = Version::factory()->create();

    $application = VersionApplication::create([
        'version_id' => $version->id,
        'student_endorsement_body' => '<p>Safe</p><script>alert(1)</script>',
        'parent_endorsement_body' => '<p>Safe</p><script>alert(2)</script>',
        'teacher_principal_endorsement_body' => '<p>Safe</p><script>alert(3)</script>',
    ]);

    expect($application->student_endorsement_body)->toContain('<p>Safe</p>');
    expect($application->student_endorsement_body)->not->toContain('<script');
    expect($application->parent_endorsement_body)->not->toContain('<script');
    expect($application->teacher_principal_endorsement_body)->not->toContain('<script');
});

test('VersionApplicationObserver only re-sanitizes a body that is dirty', function () {
    $version = Version::factory()->create();

    $application = VersionApplication::create([
        'version_id' => $version->id,
        'student_endorsement_body' => '<p>Original student text</p>',
        'parent_endorsement_body' => '<p>Original parent text</p>',
    ]);

    $cleanedStudentBody = $application->student_endorsement_body;
    $cleanedParentBody = $application->parent_endorsement_body;

    $application->update(['parent_endorsement_body' => '<p>Updated parent text</p>']);

    expect($application->fresh()->student_endorsement_body)->toBe($cleanedStudentBody);
    expect($application->fresh()->parent_endorsement_body)->not->toBe($cleanedParentBody);
    expect($application->fresh()->parent_endorsement_body)->toContain('Updated parent text');
});

test('VersionApplicationObserver leaves a null Teacher/Principal body untouched', function () {
    $version = Version::factory()->create();

    $application = VersionApplication::create([
        'version_id' => $version->id,
        'student_endorsement_body' => '<p>Student</p>',
        'parent_endorsement_body' => '<p>Parent</p>',
        'teacher_principal_endorsement_body' => null,
    ]);

    expect($application->fresh()->teacher_principal_endorsement_body)->toBeNull();
});
