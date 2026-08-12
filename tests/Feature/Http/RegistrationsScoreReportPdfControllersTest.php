<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\School;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * A results-released Version with one scored, Accepted Candidate belonging
 * to a real Teacher+School — the shared fixture for both Registrations-side
 * score report PDF controllers.
 *
 * @return array{teacher: Teacher, version: Version, school: School, candidate: Candidate}
 */
function makeRegistrationsScoreReportScenario(): array
{
    $teacherUser = User::factory()->create(['email_verified_at' => now()]);
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'score_order' => 'desc', 'results_released_at' => now()]);

    $voicePart = VoicePart::factory()->create(['name' => 'Soprano I', 'abbr' => 'SI']);
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus', 'abbreviation' => 'MC']);
    $ensemble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => 1]);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $room->voiceParts()->attach($voicePart->id);
    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $category->id, 'description' => 'Tone', 'abbreviation' => 'TN', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);
    $room->scoreCategories()->attach($category->id);
    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);

    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'voice_part_id' => $voicePart->id,
        'status' => CandidateStatus::Accepted,
        'accepted_ensemble_id' => $ensemble->id,
    ]);
    app(AdjudicationService::class)->saveScores($judge, $candidate, $version, [$factor->id => 90]);

    return compact('teacher', 'version', 'school', 'candidate');
}

test('SchoolScoreReportPdfController renders a PDF for the requesting teacher\'s own candidates at that school', function () {
    ['teacher' => $teacher, 'version' => $version, 'school' => $school] = makeRegistrationsScoreReportScenario();

    actingAs($teacher->user);

    get(route('registrations.results.school-report-pdf', ['version' => $version, 'school' => $school]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('SchoolScoreReportPdfController 404s when the teacher has no candidates at that school', function () {
    ['version' => $version] = makeRegistrationsScoreReportScenario();
    $otherTeacherUser = User::factory()->create(['email_verified_at' => now()]);
    $otherTeacher = Teacher::factory()->create(['user_id' => $otherTeacherUser->id, 'onboarding_completed_at' => now()]);
    $otherSchool = School::factory()->create();
    $otherTeacher->schools()->attach($otherSchool->id, ['is_active' => true, 'verified_at' => now()]);

    actingAs($otherTeacher->user);

    get(route('registrations.results.school-report-pdf', ['version' => $version, 'school' => $otherSchool]))
        ->assertStatus(404);
});

test('SchoolScoreReportPdfController 404s when results have not been released', function () {
    ['teacher' => $teacher, 'school' => $school] = makeRegistrationsScoreReportScenario();
    $event = Event::factory()->create();
    $openVersion = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'results_released_at' => null]);
    Candidate::factory()->create(['version_id' => $openVersion->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Accepted]);

    actingAs($teacher->user);

    get(route('registrations.results.school-report-pdf', ['version' => $openVersion, 'school' => $school]))
        ->assertStatus(404);
});

test('CandidateScoreReportPdfController renders a PDF for the requesting teacher\'s own candidate', function () {
    ['teacher' => $teacher, 'version' => $version, 'candidate' => $candidate] = makeRegistrationsScoreReportScenario();

    actingAs($teacher->user);

    get(route('registrations.results.candidate-report-pdf', [$version, $candidate]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('CandidateScoreReportPdfController 403s for a different teacher\'s candidate', function () {
    ['version' => $version, 'candidate' => $candidate] = makeRegistrationsScoreReportScenario();
    $otherTeacherUser = User::factory()->create(['email_verified_at' => now()]);
    $otherTeacher = Teacher::factory()->create(['user_id' => $otherTeacherUser->id, 'onboarding_completed_at' => now()]);
    $otherSchool = School::factory()->create();
    $otherTeacher->schools()->attach($otherSchool->id, ['is_active' => true, 'verified_at' => now()]);

    actingAs($otherTeacher->user);

    get(route('registrations.results.candidate-report-pdf', [$version, $candidate]))
        ->assertStatus(403);
});

test('CandidateScoreReportPdfController 404s when results have not been released', function () {
    ['teacher' => $teacher] = makeRegistrationsScoreReportScenario();
    $event = Event::factory()->create();
    $openVersion = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'results_released_at' => null]);
    $candidate = Candidate::factory()->create(['version_id' => $openVersion->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Accepted]);

    actingAs($teacher->user);

    get(route('registrations.results.candidate-report-pdf', [$openVersion, $candidate]))
        ->assertStatus(404);
});
