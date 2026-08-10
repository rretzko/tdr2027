<?php

declare(strict_types=1);

use App\Enums\JudgeType;
use App\Livewire\Events\TabRoom\AdjudicationTracking;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('mount succeeds for a Tab Room Manager and aborts with 403 otherwise', function () {
    $manager = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    Livewire::actingAs($manager)
        ->test(AdjudicationTracking::class, ['version' => $version])
        ->assertOk();

    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test(AdjudicationTracking::class, ['version' => $version])
        ->assertStatus(403);
});

test('defaults to the first Room and reports correct status/tolerance badges for its candidates', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'tolerance' => 2, 'order_by' => 1]);
    $voicePart = VoicePart::factory()->create();
    $room->voiceParts()->attach($voicePart->id);

    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create([
        'event_id' => $event->id,
        'version_id' => null,
        'score_category_id' => $category->id,
        'description' => 'Tone',
        'abbreviation' => 'TN',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ]);
    $room->scoreCategories()->attach($category->id);

    $judgeA = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $judgeB = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Judge2]);

    $notStarted = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $outOfTolerance = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    $adjudication = app(AdjudicationService::class);
    $adjudication->saveScores($judgeA, $outOfTolerance, $version, [$factor->id => 5]);
    $adjudication->saveScores($judgeB, $outOfTolerance, $version, [$factor->id => 1]);

    Livewire::actingAs($manager)
        ->test(AdjudicationTracking::class, ['version' => $version])
        ->assertSet('selectedRoomId', null) // no explicit selection yet — falls back to the first Room in render()
        ->assertSee($room->name)
        ->assertSee((string) $notStarted->id)
        ->assertSee((string) $outOfTolerance->id);

    // Regression guard on the underlying data, not just markup presence.
    $candidateIds = collect([$notStarted, $outOfTolerance])->pluck('id');
    $statuses = $adjudication->candidateStatuses($room, $candidateIds);
    $tolerances = $adjudication->candidateTolerances($room, $candidateIds);

    expect($statuses[$notStarted->id])->toBe('none');
    expect($statuses[$outOfTolerance->id])->toBe('completed');
    expect($tolerances[$outOfTolerance->id])->toBeFalse();
});

test('the Rooms selector shows a red/amber/green dot reflecting each Room\'s overall progress', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create([
        'event_id' => $event->id,
        'version_id' => null,
        'score_category_id' => $category->id,
        'description' => 'Tone',
        'abbreviation' => 'TN',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ]);

    $adjudication = app(AdjudicationService::class);

    // Red Room: a candidate registered, nobody scored yet.
    $redRoom = VersionRoom::create(['version_id' => $version->id, 'name' => 'Red Room', 'order_by' => 1]);
    $redVoicePart = VoicePart::factory()->create();
    $redRoom->voiceParts()->attach($redVoicePart->id);
    $redRoom->scoreCategories()->attach($category->id);
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $redVoicePart->id]);

    // Amber Room: one candidate fully scored, one not started yet.
    $amberRoom = VersionRoom::create(['version_id' => $version->id, 'name' => 'Amber Room', 'order_by' => 2]);
    $amberVoicePart = VoicePart::factory()->create();
    $amberRoom->voiceParts()->attach($amberVoicePart->id);
    $amberRoom->scoreCategories()->attach($category->id);
    $amberJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $amberRoom->id, 'judge_type' => JudgeType::HeadJudge]);
    $amberScored = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $amberVoicePart->id]);
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $amberVoicePart->id]);
    $adjudication->saveScores($amberJudge, $amberScored, $version, [$factor->id => 4]);

    // Green Room: its only candidate is fully scored.
    $greenRoom = VersionRoom::create(['version_id' => $version->id, 'name' => 'Green Room', 'order_by' => 3]);
    $greenVoicePart = VoicePart::factory()->create();
    $greenRoom->voiceParts()->attach($greenVoicePart->id);
    $greenRoom->scoreCategories()->attach($category->id);
    $greenJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $greenRoom->id, 'judge_type' => JudgeType::HeadJudge]);
    $greenCandidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $greenVoicePart->id]);
    $adjudication->saveScores($greenJudge, $greenCandidate, $version, [$factor->id => 5]);

    Livewire::actingAs($manager)
        ->test(AdjudicationTracking::class, ['version' => $version])
        ->assertSeeInOrder(['title="Red"', 'title="Amber"', 'title="Green"'], false);
});

test('a Room with an out-of-tolerance candidate shows a trailing red asterisk on its name', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create([
        'event_id' => $event->id,
        'version_id' => null,
        'score_category_id' => $category->id,
        'description' => 'Tone',
        'abbreviation' => 'TN',
        'best' => 5,
        'worst' => 1,
        'interval_by' => 1,
        'multiplier' => 1,
        'tolerance' => null,
        'order_by' => 1,
    ]);

    $adjudication = app(AdjudicationService::class);

    $flaggedRoom = VersionRoom::create(['version_id' => $version->id, 'name' => 'Flagged Room', 'tolerance' => 2, 'order_by' => 1]);
    $flaggedVoicePart = VoicePart::factory()->create();
    $flaggedRoom->voiceParts()->attach($flaggedVoicePart->id);
    $flaggedRoom->scoreCategories()->attach($category->id);
    $flaggedJudgeA = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $flaggedRoom->id, 'judge_type' => JudgeType::HeadJudge]);
    $flaggedJudgeB = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $flaggedRoom->id, 'judge_type' => JudgeType::Judge2]);
    $flaggedCandidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $flaggedVoicePart->id]);
    $adjudication->saveScores($flaggedJudgeA, $flaggedCandidate, $version, [$factor->id => 5]);
    $adjudication->saveScores($flaggedJudgeB, $flaggedCandidate, $version, [$factor->id => 1]);

    $cleanRoom = VersionRoom::create(['version_id' => $version->id, 'name' => 'Clean Room', 'tolerance' => 2, 'order_by' => 2]);
    $cleanVoicePart = VoicePart::factory()->create();
    $cleanRoom->voiceParts()->attach($cleanVoicePart->id);
    $cleanRoom->scoreCategories()->attach($category->id);
    $cleanJudge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $cleanRoom->id, 'judge_type' => JudgeType::HeadJudge]);
    $cleanCandidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $cleanVoicePart->id]);
    $adjudication->saveScores($cleanJudge, $cleanCandidate, $version, [$factor->id => 5]);

    $component = Livewire::actingAs($manager)
        ->test(AdjudicationTracking::class, ['version' => $version])
        ->assertSee('Contains an out-of-tolerance candidate');

    // Exactly one asterisk marker — Flagged Room only, not Clean Room.
    expect(substr_count($component->html(), 'Contains an out-of-tolerance candidate'))->toBe(1);
});

test('selectRoom switches the tracked Room via the #[Url] property', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $roomA = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room A', 'order_by' => 1]);
    $roomB = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room B', 'order_by' => 2]);

    Livewire::actingAs($manager)
        ->test(AdjudicationTracking::class, ['version' => $version])
        ->call('selectRoom', $roomB->id)
        ->assertSet('selectedRoomId', $roomB->id)
        ->assertSee('Room B');
});
