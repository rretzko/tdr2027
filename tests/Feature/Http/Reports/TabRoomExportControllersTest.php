<?php

declare(strict_types=1);

use App\Enums\CutoffStrategy;
use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
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
 * A smoke test that every Tab Room export controller actually renders a
 * response — the landscape PDF (Pdf::setPaper()) and the flatMap→union CSV
 * fix (TabRoomReportServiceTest covers the data; this covers the HTTP/
 * dompdf plumbing, new territory for this codebase's report exports) —
 * rather than only ever testing the Livewire pages' row data.
 *
 * @return array{manager: User, version: Version, ensemble: Ensemble, voicePart: VoicePart}
 */
function makeTabRoomExportScenario(): array
{
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::PerVoicePartPerEnsemble->value]);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $voicePart = VoicePart::factory()->create(['name' => 'Soprano I', 'abbr' => 'SI']);
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus']);
    $ensemble->voiceParts()->attach($voicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $ensemble->id, 'order_by' => 1]);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $room->voiceParts()->attach($voicePart->id);
    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $category->id, 'description' => 'Tone', 'abbreviation' => 'TN', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);
    $room->scoreCategories()->attach($category->id);
    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);

    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    app(AdjudicationService::class)->saveScores($judge, $candidate, $version, [$factor->id => 90]);

    return compact('manager', 'version', 'ensemble', 'voicePart');
}

test('AuditionScoresPdfController renders a PDF', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart] = makeTabRoomExportScenario();

    get(route('events.versions.tab-room.reports.audition-scores.pdf', ['version' => $version, 'voice_part_id' => $voicePart->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('CombinedAuditionScoresExportController renders PDF and CSV, confidential and public', function () {
    ['manager' => $manager, 'version' => $version, 'ensemble' => $ensemble] = makeTabRoomExportScenario();

    get(route('events.versions.tab-room.reports.combined-scores-confidential.export', ['version' => $version, 'ensemble_id' => $ensemble->id, 'confidential' => 1, 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    get(route('events.versions.tab-room.reports.combined-scores-public.export', ['version' => $version, 'ensemble_id' => $ensemble->id, 'confidential' => 0, 'format' => 'csv']))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('CombinedAuditionScoresExportController renders "All Ensembles" as CSV but rejects the synchronous PDF route', function () {
    ['manager' => $manager, 'version' => $version] = makeTabRoomExportScenario();

    // PDF for "All Ensembles" can't render synchronously at real data scale
    // (DomPDF exceeded a 120s/2GB limit — see GenerateCombinedScoresPdfJob's
    // docblock) — a stale bookmarked/shared link to this route must not be
    // able to reproduce that crash, so it 422s instead of streaming.
    get(route('events.versions.tab-room.reports.combined-scores-confidential.export', ['version' => $version, 'ensemble_id' => 'all', 'confidential' => 1, 'format' => 'pdf']))
        ->assertStatus(422);

    get(route('events.versions.tab-room.reports.combined-scores-public.export', ['version' => $version, 'ensemble_id' => 'all', 'confidential' => 0, 'format' => 'csv']))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('EnsembleParticipationExportController renders PDF and CSV', function () {
    ['manager' => $manager, 'version' => $version, 'ensemble' => $ensemble] = makeTabRoomExportScenario();

    get(route('events.versions.tab-room.reports.ensemble-participation.export', ['version' => $version, 'ensemble_id' => $ensemble->id, 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    get(route('events.versions.tab-room.reports.ensemble-participation.export', ['version' => $version, 'ensemble_id' => $ensemble->id, 'format' => 'csv']))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('StudentSeniorityExportController renders PDF and CSV', function () {
    ['manager' => $manager, 'version' => $version, 'ensemble' => $ensemble] = makeTabRoomExportScenario();

    get(route('events.versions.tab-room.reports.student-seniority.export', ['version' => $version, 'ensemble_id' => $ensemble->id, 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    get(route('events.versions.tab-room.reports.student-seniority.export', ['version' => $version, 'ensemble_id' => $ensemble->id, 'format' => 'csv']))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('export controllers 403 for a user with no Tab Room Manager role', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    $voicePart = VoicePart::factory()->create();
    $user = User::factory()->create();
    actingAs($user);

    get(route('events.versions.tab-room.reports.audition-scores.pdf', ['version' => $version, 'voice_part_id' => $voicePart->id]))
        ->assertStatus(403);
});
