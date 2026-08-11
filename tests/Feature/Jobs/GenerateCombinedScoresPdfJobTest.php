<?php

declare(strict_types=1);

use App\Enums\CutoffStrategy;
use App\Enums\JudgeType;
use App\Enums\PdfExportStatus;
use App\Jobs\GenerateCombinedScoresPdfJob;
use App\Mail\CombinedScoresPdfReadyMail;
use App\Models\Candidate;
use App\Models\CombinedScoresPdfExport;
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
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use App\Support\Reports\TabRoomReportCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * @return array{manager: User, version: Version}
 */
function makeGenerateCombinedScoresPdfJobScenario(): array
{
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::PerVoicePartPerEnsemble->value]);

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

    return compact('manager', 'version');
}

test('handle() renders the PDF, stores it on S3, marks the export Completed, and emails the requester', function () {
    Storage::fake('s3');
    Mail::fake();
    ['manager' => $manager, 'version' => $version] = makeGenerateCombinedScoresPdfJobScenario();

    $export = CombinedScoresPdfExport::create([
        'version_id' => $version->id,
        'confidential' => true,
        'requested_by_user_id' => $manager->id,
        'status' => PdfExportStatus::Queued,
    ]);
    $expectedGeneration = TabRoomReportCache::currentGeneration($version);

    (new GenerateCombinedScoresPdfJob($export->id))->handle(app(TabRoomReportService::class), app(EnsembleCutoffService::class));

    $export->refresh();
    expect($export->status)->toBe(PdfExportStatus::Completed);
    expect($export->report_generation)->toBe($expectedGeneration);
    expect($export->s3_key)->not->toBeNull();
    expect($export->s3_key)->toStartWith("combinedConfidentialPdfs/{$version->id}/");
    Storage::disk('s3')->assertExists($export->s3_key);

    Mail::assertSent(
        CombinedScoresPdfReadyMail::class,
        fn (CombinedScoresPdfReadyMail $mail): bool => $mail->hasTo($manager->email) && $mail->version->is($version) && count($mail->attachments()) === 1,
    );
});

test('handle() marks the export Failed and rethrows when storage fails', function () {
    ['manager' => $manager, 'version' => $version] = makeGenerateCombinedScoresPdfJobScenario();

    $export = CombinedScoresPdfExport::create([
        'version_id' => $version->id,
        'confidential' => true,
        'requested_by_user_id' => $manager->id,
        'status' => PdfExportStatus::Queued,
    ]);

    Storage::shouldReceive('disk')->with('s3')->andReturnSelf();
    Storage::shouldReceive('put')->andThrow(new RuntimeException('S3 unavailable'));

    expect(fn () => (new GenerateCombinedScoresPdfJob($export->id))->handle(app(TabRoomReportService::class), app(EnsembleCutoffService::class)))
        ->toThrow(RuntimeException::class, 'S3 unavailable');

    $export->refresh();
    expect($export->status)->toBe(PdfExportStatus::Failed);
    expect($export->failure_reason)->toBe('S3 unavailable');
});

test('handle() marks the export Failed when the S3 write silently fails (disk configured with throw => false)', function () {
    // Confirmed in production (2026-08-11): config/filesystems.php's 's3'
    // disk sets 'throw' => false, so a rejected write (an AWS 403 in that
    // incident) returns false instead of throwing — trusting that silently
    // would mark the export Completed with an s3_key pointing at an object
    // that was never actually written.
    ['manager' => $manager, 'version' => $version] = makeGenerateCombinedScoresPdfJobScenario();

    $export = CombinedScoresPdfExport::create([
        'version_id' => $version->id,
        'confidential' => true,
        'requested_by_user_id' => $manager->id,
        'status' => PdfExportStatus::Queued,
    ]);

    Storage::shouldReceive('disk')->with('s3')->andReturnSelf();
    Storage::shouldReceive('put')->andReturn(false);

    expect(fn () => (new GenerateCombinedScoresPdfJob($export->id))->handle(app(TabRoomReportService::class), app(EnsembleCutoffService::class)))
        ->toThrow(RuntimeException::class);

    $export->refresh();
    expect($export->status)->toBe(PdfExportStatus::Failed);
    expect($export->s3_key)->toBeNull();
});
