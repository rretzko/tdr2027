<?php

declare(strict_types=1);

use App\Enums\JudgeType;
use App\Livewire\Events\Adjudicate;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\School;
use App\Models\Score;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\Student;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeQualifyingVersion(User $user): Version
{
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => 'adjudication',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDay(),
    ]);

    return $version;
}

/**
 * A full scoring scenario: an active Version, a judge's Room with one
 * VoicePart/Candidate and a one-factor rubric — everything selectCandidate()/
 * save()/nextCandidate() need. Requires an authenticated user before calling
 * (CandidateObserver logs status history against the acting user).
 *
 * @return array{version: Version, room: VersionRoom, roomJudge: RoomJudge, factor: ScoreFactor, candidate: Candidate}
 */
function makeAdjudicationScenario(User $user): array
{
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 1', 'order_by' => 1]);
    $roomJudge = RoomJudge::factory()->create([
        'version_id' => $version->id,
        'room_id' => $room->id,
        'user_id' => $user->id,
        'judge_type' => JudgeType::HeadJudge,
    ]);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => 'adjudication',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDay(),
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

    return compact('version', 'room', 'roomJudge', 'factor', 'candidate');
}

test('mount succeeds for a qualifying judge, even with no Teacher record at all', function () {
    $user = User::factory()->create();
    $version = makeQualifyingVersion($user);

    Livewire::actingAs($user)
        ->test(Adjudicate::class, ['version' => $version])
        ->assertSet('version.id', $version->id)
        ->assertOk();
});

test('mount aborts with 403 when the user has no RoomJudge row on that Version', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => 'adjudication',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDay(),
    ]);

    Livewire::actingAs($user)
        ->test(Adjudicate::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount aborts with 403 when the Version is not active', function () {
    $user = User::factory()->create();
    $version = makeQualifyingVersion($user);
    $version->update(['status' => 'sandbox']);

    Livewire::actingAs($user)
        ->test(Adjudicate::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount aborts with 403 outside the adjudication window', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => 'adjudication',
        'start_at' => now()->subDays(2),
        'end_at' => now()->subDay(),
    ]);

    Livewire::actingAs($user)
        ->test(Adjudicate::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount aborts with 403 for an otherwise-qualifying judge who is a currently-enrolled student', function () {
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $school = School::factory()->create();
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => $school->senior_year]);

    $version = makeQualifyingVersion($user);

    Livewire::actingAs($user)
        ->test(Adjudicate::class, ['version' => $version])
        ->assertStatus(403);
});

test('selectCandidate prefills only the judge\'s previously-saved factors, never a default', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'roomJudge' => $roomJudge, 'factor' => $factor, 'candidate' => $candidate] = makeAdjudicationScenario($user);

    Livewire::actingAs($user)
        ->test(Adjudicate::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->assertSet('scores', []);

    app(AdjudicationService::class)->saveScores($roomJudge, $candidate, $version, [$factor->id => 4]);

    Livewire::actingAs($user)
        ->test(Adjudicate::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->assertSet("scores.{$factor->id}", 4);
});

test('save rejects an incomplete submission and an out-of-range value', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'factor' => $factor, 'candidate' => $candidate] = makeAdjudicationScenario($user);

    Livewire::actingAs($user)
        ->test(Adjudicate::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->call('save')
        ->assertHasErrors(["scores.{$factor->id}" => 'required']);

    Livewire::actingAs($user)
        ->test(Adjudicate::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->set("scores.{$factor->id}", 99)
        ->call('save')
        ->assertHasErrors(["scores.{$factor->id}" => 'in']);

    expect(Score::count())->toBe(0);
});

test('save persists scores and the Room Scoring table then appears', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'roomJudge' => $roomJudge, 'factor' => $factor, 'candidate' => $candidate] = makeAdjudicationScenario($user);

    Livewire::actingAs($user)
        ->test(Adjudicate::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->set("scores.{$factor->id}", 4)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Room Scores');

    expect(Score::where('judge_id', $roomJudge->id)->where('candidate_id', $candidate->id)->where('score_factor_id', $factor->id)->value('score'))
        ->toBe(4);
});

test('the Room Scoring table stays hidden from a different judge who has not saved their own scores yet, even though another judge already has', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'room' => $room, 'roomJudge' => $firstJudge, 'factor' => $factor, 'candidate' => $candidate] = makeAdjudicationScenario($user);

    app(AdjudicationService::class)->saveScores($firstJudge, $candidate, $version, [$factor->id => 4]);

    $secondUser = User::factory()->create();
    $secondJudge = RoomJudge::factory()->create([
        'version_id' => $version->id,
        'room_id' => $room->id,
        'user_id' => $secondUser->id,
        'judge_type' => JudgeType::Judge2,
    ]);

    Livewire::actingAs($secondUser)
        ->test(Adjudicate::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->assertDontSee('Room Scores');

    app(AdjudicationService::class)->saveScores($secondJudge, $candidate, $version, [$factor->id => 5]);

    Livewire::actingAs($secondUser)
        ->test(Adjudicate::class, ['version' => $version])
        ->call('selectCandidate', $candidate->id)
        ->assertSee('Room Scores');
});

test('nextCandidate skips a candidate this judge has already fully scored', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'room' => $room, 'roomJudge' => $roomJudge, 'factor' => $factor, 'candidate' => $scoredCandidate] = makeAdjudicationScenario($user);

    $voicePart = $scoredCandidate->voicePart;
    $unscoredCandidate = Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    app(AdjudicationService::class)->saveScores($roomJudge, $scoredCandidate, $version, [$factor->id => 4]);

    $component = Livewire::actingAs($user)->test(Adjudicate::class, ['version' => $version]);

    // No candidate selected yet — nextCandidate should land on the first
    // unscored one, skipping the already-fully-scored candidate wherever it
    // falls in roster order.
    $component->call('nextCandidate')
        ->assertSet('selectedCandidateId', $unscoredCandidate->id);
});
