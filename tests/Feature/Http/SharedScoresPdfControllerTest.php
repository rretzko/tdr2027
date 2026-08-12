<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\PdfExportStatus;
use App\Models\Candidate;
use App\Models\CombinedScoresPdfExport;
use App\Models\Event;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * @return array{teacher: Teacher, version: Version}
 */
function makeSharedScoresScenario(bool $shareResults = true): array
{
    $teacherUser = User::factory()->create(['email_verified_at' => now()]);
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now(), 'share_results' => $shareResults]);

    actingAs($teacherUser);
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Accepted]);

    return compact('teacher', 'version');
}

test('404s when share_results is off', function () {
    ['teacher' => $teacher, 'version' => $version] = makeSharedScoresScenario(shareResults: false);
    actingAs($teacher->user);

    get(route('registrations.results.shared-scores-pdf', $version))
        ->assertStatus(404);
});

test('404s when share_results is on but no Completed export exists yet', function () {
    ['teacher' => $teacher, 'version' => $version] = makeSharedScoresScenario();
    actingAs($teacher->user);

    get(route('registrations.results.shared-scores-pdf', $version))
        ->assertStatus(404);

    CombinedScoresPdfExport::create([
        'version_id' => $version->id,
        'confidential' => false,
        'requested_by_user_id' => $teacher->user_id,
        'report_generation' => 0,
        's3_key' => null,
        'status' => PdfExportStatus::Queued->value,
    ]);

    get(route('registrations.results.shared-scores-pdf', $version))
        ->assertStatus(404);
});

test('redirects to a signed S3 URL when share_results is on and the export is Completed', function () {
    Storage::fake('s3');

    ['teacher' => $teacher, 'version' => $version] = makeSharedScoresScenario();
    CombinedScoresPdfExport::create([
        'version_id' => $version->id,
        'confidential' => false,
        'requested_by_user_id' => $teacher->user_id,
        'report_generation' => 0,
        's3_key' => 'combinedPublicPdfs/'.$version->id.'/0.pdf',
        'status' => PdfExportStatus::Completed->value,
    ]);

    actingAs($teacher->user);

    get(route('registrations.results.shared-scores-pdf', $version))
        ->assertRedirect();
});

test('403s for a teacher with no standing in the Version', function () {
    ['version' => $version] = makeSharedScoresScenario();
    CombinedScoresPdfExport::create([
        'version_id' => $version->id,
        'confidential' => false,
        'requested_by_user_id' => User::factory()->create()->id,
        'report_generation' => 0,
        's3_key' => 'combinedPublicPdfs/'.$version->id.'/0.pdf',
        'status' => PdfExportStatus::Completed->value,
    ]);

    $otherTeacherUser = User::factory()->create(['email_verified_at' => now()]);
    $otherTeacher = Teacher::factory()->create(['user_id' => $otherTeacherUser->id, 'onboarding_completed_at' => now()]);
    $otherSchool = School::factory()->create();
    $otherTeacher->schools()->attach($otherSchool->id, ['is_active' => true, 'verified_at' => now()]);
    actingAs($otherTeacher->user);

    get(route('registrations.results.shared-scores-pdf', $version))
        ->assertStatus(403);
});
