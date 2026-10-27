<?php

declare(strict_types=1);

use App\Enums\JudgeType;
use App\Livewire\Events\TabRoom\AddEditScores;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Student;
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
 * A Tab Room Manager, an active Version, one Room/judge/one-factor rubric,
 * and a registered Candidate — everything search()/save()/changeVoicePart()
 * need. Mirrors AdjudicateTest.php's makeAdjudicationScenario(), but the
 * acting user is a Tab Room Manager, not the RoomJudge themselves.
 *
 * @return array{manager: User, version: Version, room: VersionRoom, roomJudge: RoomJudge, factor: ScoreFactor, candidate: Candidate, voicePart: VoicePart}
 */
function makeTabRoomScenario(): array
{
    $manager = User::factory()->create();
    actingAs($manager); // CandidateObserver logs status history against the acting user.
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $roomJudge = RoomJudge::factory()->create([
        'version_id' => $version->id,
        'room_id' => $room->id,
        'judge_type' => JudgeType::HeadJudge,
    ]);

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

    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    return compact('manager', 'version', 'room', 'roomJudge', 'factor', 'candidate', 'voicePart');
}

test('mount succeeds for a Tab Room Manager', function () {
    ['manager' => $manager, 'version' => $version] = makeTabRoomScenario();

    Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->assertOk();
});

test('mount aborts with 403 for a user with no Tab Room Manager role', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);

    Livewire::actingAs($user)
        ->test(AddEditScores::class, ['version' => $version])
        ->assertStatus(403);
});

test('search by exact candidate id selects the candidate and defaults to its first room/judge', function () {
    ['manager' => $manager, 'version' => $version, 'roomJudge' => $roomJudge, 'candidate' => $candidate] = makeTabRoomScenario();

    Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->set('candidateIdSearch', (string) $candidate->id)
        ->call('search')
        ->assertSet('selectedCandidateId', $candidate->id)
        ->assertSet('selectedJudgeId', $roomJudge->id);
});

test('search with no match leaves no candidate selected', function () {
    ['manager' => $manager, 'version' => $version] = makeTabRoomScenario();

    Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->set('candidateIdSearch', '999999')
        ->call('search')
        ->assertSet('selectedCandidateId', null);
});

test('search by last name with multiple hits returns a pick list instead of auto-selecting', function () {
    ['manager' => $manager, 'version' => $version, 'voicePart' => $voicePart] = makeTabRoomScenario();

    $userA = User::factory()->create(['last_name' => 'Smith']);
    $studentA = Student::factory()->create(['user_id' => $userA->id]);
    $candidateA = Candidate::factory()->registered()->create(['version_id' => $version->id, 'student_id' => $studentA->id, 'voice_part_id' => $voicePart->id]);

    $userB = User::factory()->create(['last_name' => 'Smith']);
    $studentB = Student::factory()->create(['user_id' => $userB->id]);
    $candidateB = Candidate::factory()->registered()->create(['version_id' => $version->id, 'student_id' => $studentB->id, 'voice_part_id' => $voicePart->id]);

    $component = Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->set('lastNameSearch', 'Smith')
        ->call('search')
        ->assertSet('selectedCandidateId', null);

    expect($component->get('matches'))->toHaveCount(2);
    expect(collect($component->get('matches'))->pluck('id')->all())->toEqualCanonicalizing([$candidateA->id, $candidateB->id]);
});

test('save persists a score with overridden_by_user_id stamped to the acting Tab Room Manager', function () {
    ['manager' => $manager, 'version' => $version, 'roomJudge' => $roomJudge, 'factor' => $factor, 'candidate' => $candidate] = makeTabRoomScenario();

    Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->set("scores.{$factor->id}", 4)
        ->call('save')
        ->assertHasNoErrors();

    $row = Score::where('judge_id', $roomJudge->id)->where('candidate_id', $candidate->id)->first();
    expect($row->score)->toBe(4);
    expect($row->overridden_by_user_id)->toBe($manager->id);
    expect($row->overridden_at)->not->toBeNull();
});

test('the Judge Summary Table flags an out-of-tolerance room after saving a score that breaks it', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'tolerance' => 2, 'order_by' => 1]);
    $judgeA = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::HeadJudge]);
    $judgeB = RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'judge_type' => JudgeType::Judge2]);

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

    $candidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    // Judge A already scored high; Judge B is about to score far lower,
    // breaking the room's 2-point tolerance (5 - 1 = 4 > 2).
    app(AdjudicationService::class)->saveScores($judgeA, $candidate, $version, [$factor->id => 5]);

    Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->call('selectJudge', $judgeB->id)
        ->set("scores.{$factor->id}", 1)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('2-point tolerance');
});

test('changeVoicePart removes existing scores and non-relevant recordings, then re-derives rooms for the new voice part', function () {
    ['manager' => $manager, 'version' => $version, 'room' => $room, 'roomJudge' => $roomJudge, 'factor' => $factor, 'candidate' => $candidate] = makeTabRoomScenario();

    app(AdjudicationService::class)->saveScores($roomJudge, $candidate, $version, [$factor->id => 4]);

    $newVoicePart = VoicePart::factory()->create();
    $newRoom = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 2', 'order_by' => 2]);
    $newRoom->voiceParts()->attach($newVoicePart->id);
    $newCategory = ScoreCategory::create(['event_id' => $version->event_id, 'version_id' => null, 'description' => 'Solo', 'order_by' => 2]);
    $newRoom->scoreCategories()->attach($newCategory->id);

    $approver = User::factory()->create();
    $nonRelevant = Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'file_type' => 'Scales',
        'uploaded_by' => $approver->id,
        'approved_at' => now(),
        'approved_by' => $approver->id,
        'url' => 'recordings/scales.mp3',
    ]);

    Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->set('newVoicePartId', (string) $newVoicePart->id)
        ->call('changeVoicePart')
        ->assertSet('selectedCandidateId', $candidate->id);

    expect(Score::where('candidate_id', $candidate->id)->count())->toBe(0);
    expect(Recording::find($nonRelevant->id))->toBeNull();
    expect($candidate->fresh()->voice_part_id)->toBe($newVoicePart->id);
});

test('search finds a candidate already resolved by Ensemble Cut-offs, and warns that cut-offs need re-evaluating', function () {
    ['manager' => $manager, 'version' => $version, 'candidate' => $candidate] = makeTabRoomScenario();
    $candidate->update(['status' => 'accepted']);

    Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->set('candidateIdSearch', (string) $candidate->id)
        ->call('search')
        ->assertSet('selectedCandidateId', $candidate->id)
        ->assertSee('already been resolved by Ensemble Cut-offs')
        ->assertDontSee('the audition must be');
});

test('a registered candidate shows no cut-off warning', function () {
    ['manager' => $manager, 'version' => $version, 'candidate' => $candidate] = makeTabRoomScenario();

    Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->assertDontSee('already been resolved by Ensemble Cut-offs');
});

test('an accepted candidate on an active version can still have scores saved', function () {
    ['manager' => $manager, 'version' => $version, 'roomJudge' => $roomJudge, 'factor' => $factor, 'candidate' => $candidate] = makeTabRoomScenario();
    $candidate->update(['status' => 'accepted']);

    Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->set("scores.{$factor->id}", 3)
        ->call('save')
        ->assertHasNoErrors();

    expect(Score::where('judge_id', $roomJudge->id)->where('candidate_id', $candidate->id)->value('score'))->toBe(3);
});

test('a closed version shows scores with a reopen advisory linking to Close Audition, and suppresses the cut-off warning', function () {
    ['manager' => $manager, 'version' => $version, 'roomJudge' => $roomJudge, 'factor' => $factor, 'candidate' => $candidate] = makeTabRoomScenario();
    app(AdjudicationService::class)->saveScores($roomJudge, $candidate, $version, [$factor->id => 4]);
    $candidate->update(['status' => 'accepted']);
    $version->update(['status' => 'closed']);

    Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->assertSet("scores.{$factor->id}", 4)
        ->assertSee('To change scores, the audition must be')
        ->assertSee(route('events.versions.tab-room.close', $version), escape: false)
        ->assertDontSee('already been resolved by Ensemble Cut-offs');
});

test('save is rejected on a closed version', function () {
    ['manager' => $manager, 'version' => $version, 'roomJudge' => $roomJudge, 'factor' => $factor, 'candidate' => $candidate] = makeTabRoomScenario();

    $component = Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->set("scores.{$factor->id}", 4);

    $version->update(['status' => 'closed']);

    $component->call('save')->assertStatus(422);

    expect(Score::where('judge_id', $roomJudge->id)->where('candidate_id', $candidate->id)->exists())->toBeFalse();
});

test('changeVoicePart is rejected on a closed version', function () {
    ['manager' => $manager, 'version' => $version, 'candidate' => $candidate, 'voicePart' => $voicePart] = makeTabRoomScenario();
    $newVoicePart = VoicePart::factory()->create();

    $component = Livewire::actingAs($manager)
        ->test(AddEditScores::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->set('newVoicePartId', (string) $newVoicePart->id);

    $version->update(['status' => 'closed']);

    $component->call('changeVoicePart')->assertStatus(422);

    expect($candidate->fresh()->voice_part_id)->toBe($voicePart->id);
});
