<?php

declare(strict_types=1);

use App\Enums\CutoffStrategy;
use App\Enums\JudgeType;
use App\Livewire\Events\TabRoom\Reports\AuditionScores;
use App\Models\Candidate;
use App\Models\Ensemble;
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

/**
 * @return array{manager: User, version: Version, voicePart: VoicePart, high: Candidate, low: Candidate}
 */
function makeAuditionScoresScenario(): array
{
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'score_order' => 'desc', 'cutoff_strategy' => CutoffStrategy::PerVoicePartPerEnsemble->value]);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $voicePart = VoicePart::factory()->create(['name' => 'Soprano I', 'abbr' => 'SI']);
    // availableVoiceParts() unions voice parts across the Event's Ensembles —
    // a Voice Part only attached to a Room (not an Ensemble) never appears
    // in the dropdown, so this must exist for the default-selection fix to
    // have anything to select.
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    $ensemble->voiceParts()->attach($voicePart->id);
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $room->voiceParts()->attach($voicePart->id);
    $category = ScoreCategory::create(['event_id' => $event->id, 'version_id' => null, 'description' => 'Scales', 'order_by' => 1]);
    $factor = ScoreFactor::create(['event_id' => $event->id, 'version_id' => null, 'score_category_id' => $category->id, 'description' => 'Tone', 'abbreviation' => 'TN', 'best' => 100, 'worst' => 0, 'interval_by' => 1, 'multiplier' => 1, 'tolerance' => null, 'order_by' => 1]);
    $room->scoreCategories()->attach($category->id);
    $judge = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);

    $high = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    $low = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);
    app(AdjudicationService::class)->saveScores($judge, $high, $version, [$factor->id => 90]);
    app(AdjudicationService::class)->saveScores($judge, $low, $version, [$factor->id => 40]);

    return compact('manager', 'version', 'voicePart', 'high', 'low');
}

test('mount succeeds for a Tab Room Manager', function () {
    ['manager' => $manager, 'version' => $version] = makeAuditionScoresScenario();

    Livewire::actingAs($manager)
        ->test(AuditionScores::class, ['version' => $version])
        ->assertOk();
});

test('mount aborts with 403 for a user with no Tab Room Manager role', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AuditionScores::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount defaults voicePartId to the first available Voice Part, so the table matches what the <select> already shows selected', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart] = makeAuditionScoresScenario();

    Livewire::actingAs($manager)
        ->test(AuditionScores::class, ['version' => $version])
        ->assertSet('voicePartId', $voicePart->id)
        ->assertSee('90')
        ->assertSee('40')
        ->assertSee('err'); // still Registered — no cutoff applied — falls to the "err" (any other condition) bucket
});

test('an explicit voicePartId from the URL is not overridden by the default', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart] = makeAuditionScoresScenario();
    $otherVoicePart = VoicePart::factory()->create(['name' => 'Tenor I', 'abbr' => 'TI']);

    Livewire::actingAs($manager)
        ->withQueryParams(['voicePartId' => $otherVoicePart->id])
        ->test(AuditionScores::class, ['version' => $version])
        ->assertSet('voicePartId', $otherVoicePart->id);
});

test('choosing a different Voice Part shows every candidate best-to-worst', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart] = makeAuditionScoresScenario();

    Livewire::actingAs($manager)
        ->test(AuditionScores::class, ['version' => $version])
        ->set('voicePartId', $voicePart->id)
        ->assertSee('90')
        ->assertSee('40')
        ->assertSee('err'); // still Registered — no cutoff applied — falls to the "err" (any other condition) bucket
});
