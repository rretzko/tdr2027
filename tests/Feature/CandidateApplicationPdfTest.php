<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\VersionApplicationStatus;
use App\Models\Candidate;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function makePdfTestTeacher(): Teacher
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    return $teacher;
}

function publishApplicationForPdfTest(Version $version): VersionApplication
{
    return VersionApplication::create([
        'version_id' => $version->id,
        'student_endorsement_body' => '<p>Student endorsement text.</p>',
        'parent_endorsement_body' => '<p>Parent endorsement text.</p>',
        'teacher_principal_endorsement_body' => $version->getRawOriginal('application_type') === ApplicationType::Pdf->value
            ? '<p>Teacher/principal endorsement text.</p>'
            : null,
        'status' => VersionApplicationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);
}

test('returns 404 when no Application exists for the Version', function () {
    $teacher = makePdfTestTeacher();
    actingAs($teacher->user);
    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    get(route('registrations.candidate.application-pdf', [$version, $candidate]))
        ->assertNotFound();
});

test('returns 404 when the Application is still Draft', function () {
    $teacher = makePdfTestTeacher();
    actingAs($teacher->user);
    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    VersionApplication::create([
        'version_id' => $version->id,
        'student_endorsement_body' => '<p>Text.</p>',
        'parent_endorsement_body' => '<p>Text.</p>',
    ]);

    get(route('registrations.candidate.application-pdf', [$version, $candidate]))
        ->assertNotFound();
});

test('returns 403 when the requesting teacher does not own the candidate', function () {
    $otherTeacher = makePdfTestTeacher();
    actingAs($otherTeacher->user);
    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $otherTeacher->id]);

    publishApplicationForPdfTest($version);

    $teacher = makePdfTestTeacher();
    actingAs($teacher->user);

    get(route('registrations.candidate.application-pdf', [$version, $candidate]))
        ->assertForbidden();
});

test('returns a PDF with real candidate data for a Pdf-mode Version, including the Teacher/Principal section', function () {
    $teacher = makePdfTestTeacher();
    actingAs($teacher->user);
    $version = Version::factory()->create(['application_type' => ApplicationType::Pdf->value]);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'program_name' => 'Jane Q. Student',
    ]);

    publishApplicationForPdfTest($version);

    get(route('registrations.candidate.application-pdf', [$version, $candidate]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('returns a PDF with real candidate data for an EApplication-mode Version, omitting the Teacher/Principal section', function () {
    $teacher = makePdfTestTeacher();
    actingAs($teacher->user);
    $version = Version::factory()->create(['application_type' => ApplicationType::EApplication->value]);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'program_name' => 'Jane Q. Student',
    ]);

    publishApplicationForPdfTest($version);

    get(route('registrations.candidate.application-pdf', [$version, $candidate]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
