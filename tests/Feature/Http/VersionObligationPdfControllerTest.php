<?php

declare(strict_types=1);

use App\Enums\VersionInvitationStatus;
use App\Enums\VersionObligationStatus;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function makeObligationPdfTeacher(): Teacher
{
    $user = User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    // 'has.active.school' route middleware gates the whole registrations
    // group, independent of the obligations invitation being tested.
    $teacher->schools()->attach(School::factory()->create()->id, ['is_active' => true, 'verified_at' => now()]);

    return $teacher;
}

function inviteTeacherToObligationPdfVersion(Teacher $teacher, Version $version): VersionInvitation
{
    return VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

function publishObligationPdfFor(Version $version, string $body = '<p>Be excellent.</p>'): VersionObligation
{
    return VersionObligation::create([
        'version_id' => $version->id,
        'body' => $body,
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);
}

test('aborts with 403 for a user with no teacher profile', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('registrations.obligations-pdf', $version))->assertForbidden();
});

test('aborts with 404 for a teacher with no invitation for this Version', function () {
    $teacher = makeObligationPdfTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);

    get(route('registrations.obligations-pdf', $version))->assertNotFound();
});

test('aborts with 404 when the obligation is not published', function () {
    $teacher = makeObligationPdfTeacher();
    $version = Version::factory()->create();
    inviteTeacherToObligationPdfVersion($teacher, $version);

    actingAs($teacher->user);

    get(route('registrations.obligations-pdf', $version))->assertNotFound();
});

test('returns a PDF with merge fields resolved for an invited teacher', function () {
    $teacher = makeObligationPdfTeacher();
    $version = Version::factory()->create(['short_name' => 'TDR27']);
    inviteTeacherToObligationPdfVersion($teacher, $version);
    publishObligationPdfFor($version, '<p>Welcome to {{versionShortName}}.</p>');

    actingAs($teacher->user);

    get(route('registrations.obligations-pdf', $version))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
