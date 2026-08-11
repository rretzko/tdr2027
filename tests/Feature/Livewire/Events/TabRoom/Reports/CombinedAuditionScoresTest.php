<?php

declare(strict_types=1);

use App\Enums\CutoffStrategy;
use App\Enums\JudgeType;
use App\Enums\PdfExportStatus;
use App\Jobs\GenerateCombinedScoresPdfJob;
use App\Livewire\Events\TabRoom\Reports\CombinedAuditionScoresConfidential;
use App\Livewire\Events\TabRoom\Reports\CombinedAuditionScoresPublic;
use App\Mail\CombinedScoresPdfReadyMail;
use App\Models\Candidate;
use App\Models\CombinedScoresPdfExport;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\School;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use App\Services\EnsembleCutoffService;
use App\Support\Reports\TabRoomReportCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * @return array{manager: User, version: Version, ensemble: Ensemble, candidate: Candidate, voicePart: VoicePart}
 */
function makeCombinedScoresScenario(): array
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

    // Explicit short name — the combined Student/School/Teacher column
    // truncates school name to 25 chars, and factory-default names
    // (fake company + " High School") can randomly exceed that.
    $school = School::factory()->create(['name' => 'Springfield High']);
    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'school_id' => $school->id]);
    app(AdjudicationService::class)->saveScores($judge, $candidate, $version, [$factor->id => 90]);

    // combinedScoreRows() (a single Ensemble selected) only shows Candidates
    // actually accepted into that Ensemble — a cutoff must run for the
    // fixture's Candidate to appear in those assertions.
    app(EnsembleCutoffService::class)->applyCutoff($version, $voicePart, 0);

    return compact('manager', 'version', 'ensemble', 'candidate', 'voicePart');
}

test('confidential mount succeeds for a Tab Room Manager and shows identity columns', function () {
    ['manager' => $manager, 'version' => $version, 'ensemble' => $ensemble, 'candidate' => $candidate, 'voicePart' => $voicePart] = makeCombinedScoresScenario();

    Livewire::actingAs($manager)
        ->test(CombinedAuditionScoresConfidential::class, ['version' => $version])
        ->set('ensembleId', $ensemble->id)
        ->assertSee($candidate->student->user->sort_name)
        ->assertSee($candidate->school->name)
        ->assertSeeInOrder(['VP', $voicePart->abbr])
        ->assertSeeInOrder([$ensemble->name, '(1)'])
        ->assertSeeInOrder([$voicePart->name, "({$voicePart->abbr}) @ 1"]);
});

test('public variant omits identity columns', function () {
    ['manager' => $manager, 'version' => $version, 'ensemble' => $ensemble, 'candidate' => $candidate] = makeCombinedScoresScenario();

    Livewire::actingAs($manager)
        ->test(CombinedAuditionScoresPublic::class, ['version' => $version])
        ->set('ensembleId', $ensemble->id)
        ->assertSee('90')
        ->assertDontSee($candidate->student->user->sort_name);
});

test('confidential variant truncates a school name over 25 characters', function () {
    ['manager' => $manager, 'version' => $version, 'ensemble' => $ensemble, 'candidate' => $candidate] = makeCombinedScoresScenario();
    $longName = 'Northwest Regional Consolidated High School'; // 44 chars
    $candidate->school->update(['name' => $longName]);

    $html = Livewire::actingAs($manager)
        ->test(CombinedAuditionScoresConfidential::class, ['version' => $version])
        ->set('ensembleId', $ensemble->id)
        ->html();

    expect($html)->toContain(Str::limit($longName, 25));
    expect($html)->not->toContain($longName);
});

test('selecting "All Ensembles" shows every Voice Part\'s table under one Version-labeled section, not one per Ensemble', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $firstVoicePart, 'candidate' => $firstCandidate] = makeCombinedScoresScenario();

    $secondVoicePart = VoicePart::factory()->create(['name' => 'Tenor I', 'abbr' => 'TI']);
    $secondEnsemble = Ensemble::factory()->create(['event_id' => $version->event_id, 'name' => 'Treble Choir']);
    $secondEnsemble->voiceParts()->attach($secondVoicePart->id);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $secondEnsemble->id, 'order_by' => 2]);

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 2', 'order_by' => 2]);
    $room->voiceParts()->attach($secondVoicePart->id);
    $category = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Solo', 'order_by' => 2]);
    $factor = ScoreFactor::create(['event_id' => $version->event_id, 'version_id' => null, 'score_category_id' => $category->id, 'description' => 'Expression', 'abbreviation' => 'EX', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);
    $room->scoreCategories()->attach($category->id);
    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $secondCandidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $secondVoicePart->id]);
    app(AdjudicationService::class)->saveScores($judge, $secondCandidate, $version, [$factor->id => 80]);

    $component = Livewire::actingAs($manager)
        ->test(CombinedAuditionScoresConfidential::class, ['version' => $version])
        ->set('ensembleId', 'all');

    $component->assertSeeInOrder([$version->name, $firstVoicePart->name, $secondVoicePart->name])
        ->assertSee($firstCandidate->student->user->sort_name)
        ->assertSee($secondCandidate->student->user->sort_name);

    $html = $component->html();

    // The section heading (Version name followed by its "(count)" suffix —
    // distinct from the page subtitle's own, differently-punctuated use of
    // $version->name) appears exactly once — no per-Ensemble repetition,
    // per the product owner (2026-08-11): "All Ensembles" has no
    // meaningful per-Ensemble grouping, only a per-Candidate Result.
    expect(substr_count($html, "{$version->name} ("))->toBe(1);
});

test('requestAllEnsemblesPdf queues a job and records a Queued export row on first request', function () {
    Queue::fake();
    ['manager' => $manager, 'version' => $version] = makeCombinedScoresScenario();

    Livewire::actingAs($manager)
        ->test(CombinedAuditionScoresConfidential::class, ['version' => $version])
        ->set('ensembleId', 'all')
        ->call('requestAllEnsemblesPdf');

    Queue::assertPushed(GenerateCombinedScoresPdfJob::class);
    $export = CombinedScoresPdfExport::where('version_id', $version->id)->where('confidential', true)->sole();
    expect($export->status)->toBe(PdfExportStatus::Queued);
    expect($export->requested_by_user_id)->toBe($manager->id);
});

test('requestAllEnsemblesPdf does not requeue while an export is already in progress', function () {
    Queue::fake();
    ['manager' => $manager, 'version' => $version] = makeCombinedScoresScenario();
    CombinedScoresPdfExport::create(['version_id' => $version->id, 'confidential' => true, 'requested_by_user_id' => $manager->id, 'status' => PdfExportStatus::Processing]);

    Livewire::actingAs($manager)
        ->test(CombinedAuditionScoresConfidential::class, ['version' => $version])
        ->set('ensembleId', 'all')
        ->call('requestAllEnsemblesPdf');

    Queue::assertNotPushed(GenerateCombinedScoresPdfJob::class);
});

test('requestAllEnsemblesPdf requeues a job when a Processing row has been abandoned for over 30 minutes', function () {
    Queue::fake();
    ['manager' => $manager, 'version' => $version] = makeCombinedScoresScenario();
    $export = CombinedScoresPdfExport::create(['version_id' => $version->id, 'confidential' => true, 'requested_by_user_id' => $manager->id, 'status' => PdfExportStatus::Processing]);
    // updated_at isn't Fillable — update() would silently strip it.
    $export->timestamps = false;
    $export->updated_at = now()->subMinutes(31);
    $export->save();

    Livewire::actingAs($manager)
        ->test(CombinedAuditionScoresConfidential::class, ['version' => $version])
        ->set('ensembleId', 'all')
        ->call('requestAllEnsemblesPdf');

    Queue::assertPushed(GenerateCombinedScoresPdfJob::class);
});

test('requestAllEnsemblesPdf re-emails a still-fresh export instead of requeuing', function () {
    Queue::fake();
    Mail::fake();
    Storage::fake('s3');
    ['manager' => $manager, 'version' => $version] = makeCombinedScoresScenario();
    $generation = TabRoomReportCache::currentGeneration($version);
    Storage::disk('s3')->put('combinedConfidentialPdfs/existing.pdf', '%PDF-1.4 fake');
    CombinedScoresPdfExport::create([
        'version_id' => $version->id, 'confidential' => true, 'requested_by_user_id' => $manager->id,
        'status' => PdfExportStatus::Completed, 'report_generation' => $generation, 's3_key' => 'combinedConfidentialPdfs/existing.pdf',
    ]);

    Livewire::actingAs($manager)
        ->test(CombinedAuditionScoresConfidential::class, ['version' => $version])
        ->set('ensembleId', 'all')
        ->call('requestAllEnsemblesPdf');

    Queue::assertNotPushed(GenerateCombinedScoresPdfJob::class);
    Mail::assertSent(CombinedScoresPdfReadyMail::class, fn (CombinedScoresPdfReadyMail $mail): bool => $mail->hasTo($manager->email));
});

test('requestAllEnsemblesPdf falls back to requeuing when a "Completed" export\'s S3 object is missing or unreadable', function () {
    // Confirmed in production (2026-08-11): config/filesystems.php's 's3'
    // disk sets 'throw' => false, so Storage::get() on a missing/rejected
    // object returns null rather than throwing — a stale Completed row
    // must not crash this Mailable's typed constructor, it should just
    // regenerate instead.
    Queue::fake();
    ['manager' => $manager, 'version' => $version] = makeCombinedScoresScenario();
    $generation = TabRoomReportCache::currentGeneration($version);
    CombinedScoresPdfExport::create([
        'version_id' => $version->id, 'confidential' => true, 'requested_by_user_id' => $manager->id,
        'status' => PdfExportStatus::Completed, 'report_generation' => $generation, 's3_key' => 'combinedConfidentialPdfs/87/0.pdf',
    ]);

    Storage::shouldReceive('disk')->with('s3')->andReturnSelf();
    Storage::shouldReceive('get')->andReturnNull();

    Livewire::actingAs($manager)
        ->test(CombinedAuditionScoresConfidential::class, ['version' => $version])
        ->set('ensembleId', 'all')
        ->call('requestAllEnsemblesPdf');

    Queue::assertPushed(GenerateCombinedScoresPdfJob::class);
});

test('requestAllEnsemblesPdf queues a fresh job when the report generation has changed since the last export', function () {
    Queue::fake();
    ['manager' => $manager, 'version' => $version] = makeCombinedScoresScenario();
    $generation = TabRoomReportCache::currentGeneration($version);
    CombinedScoresPdfExport::create([
        'version_id' => $version->id, 'confidential' => true, 'requested_by_user_id' => $manager->id,
        'status' => PdfExportStatus::Completed, 'report_generation' => $generation, 's3_key' => 'combinedConfidentialPdfs/stale.pdf',
    ]);

    // Simulates the generation-bump a Score save or cutoff decision already
    // triggers in production (TabRoomReportServiceTest covers that this
    // really happens via AdjudicationService/EnsembleCutoffService) —
    // calling forget() directly here keeps this test scoped to what
    // requestAllEnsemblesPdf() itself does with a changed generation.
    TabRoomReportCache::forget($version);

    Livewire::actingAs($manager)
        ->test(CombinedAuditionScoresConfidential::class, ['version' => $version])
        ->set('ensembleId', 'all')
        ->call('requestAllEnsemblesPdf');

    Queue::assertPushed(GenerateCombinedScoresPdfJob::class);
});

test('confidential mount aborts with 403 for a user with no Tab Room Manager role', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CombinedAuditionScoresConfidential::class, ['version' => $version])
        ->assertStatus(403);
});
