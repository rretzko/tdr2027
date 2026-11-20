<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\JudgeSurvey\JudgeCheckpoint;
use App\Enums\JudgeSurvey\JudgeDeviceType;
use App\Enums\JudgeSurvey\JudgeFocus;
use App\Enums\JudgeSurvey\JudgeObstacle;
use App\Enums\JudgeSurvey\ListeningDevice;
use App\Enums\JudgeType;
use App\Livewire\Events\Adjudicate;
use App\Livewire\Events\VersionScorecard;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\JudgeSurveyResponse;
use App\Models\Organization;
use App\Models\RoomJudge;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\JudgeSurveySummaryService;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('s3'));

/**
 * A Room with $count registered candidates and a one-factor rubric, judged
 * by $user. Requires an authenticated user (CandidateObserver).
 *
 * @return array{version: Version, room: VersionRoom, roomJudge: RoomJudge, factor: ScoreFactor, candidates: Collection<int, Candidate>}
 */
function makeCheckpointScenario(User $user, int $count): array
{
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Room 7', 'order_by' => 1]);
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

    $candidates = Candidate::factory()->registered()->count($count)->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id]);

    return compact('version', 'room', 'roomJudge', 'factor', 'candidates');
}

/**
 * Scores the given candidates, in order, on one Livewire test instance.
 */
function scoreCandidates(mixed $component, ScoreFactor $factor, Collection $candidates): mixed
{
    foreach ($candidates as $candidate) {
        $component->call('selectCandidate', $candidate->id)
            ->set("scores.{$factor->id}", '3')
            ->call('save');
    }

    return $component;
}

test('checkpoint thresholds fall at one-third, two-thirds and one-to-go', function () {
    expect(JudgeCheckpoint::thresholds(30))->toBe(['first' => 10, 'second' => 20, 'final' => 29])
        ->and(JudgeCheckpoint::thresholds(6))->toBe(['first' => 2, 'second' => 4, 'final' => 5])
        ->and(JudgeCheckpoint::thresholds(7))->toBe(['first' => 3, 'second' => 5, 'final' => 6]);
});

test('small rooms skip the two-thirds checkpoint and collapse colliding ones', function () {
    expect(JudgeCheckpoint::thresholds(5))->toBe(['first' => 2, 'final' => 4])
        ->and(JudgeCheckpoint::thresholds(3))->toBe(['first' => 1, 'final' => 2])
        ->and(JudgeCheckpoint::thresholds(2))->toBe(['final' => 1])
        ->and(JudgeCheckpoint::thresholds(1))->toBe([])
        ->and(JudgeCheckpoint::thresholds(0))->toBe([]);
});

test('messages fill in the judge and their progress', function () {
    expect(JudgeCheckpoint::fill('{name}: {done} of {total}, {remaining} left in {room}', ['name' => 'Ana', 'done' => 4, 'total' => 12, 'remaining' => 8, 'room' => 'Room 7']))
        ->toBe('Ana: 4 of 12, 8 left in Room 7');

    foreach (JudgeCheckpoint::cases() as $checkpoint) {
        expect($checkpoint->messages())->not->toBeEmpty()
            ->and($checkpoint->tips())->not->toBeEmpty();
    }
});

test('the device type is detected from the screen and pointer', function () {
    expect(JudgeDeviceType::detect(390, 844, true))->toBe(JudgeDeviceType::Phone)
        ->and(JudgeDeviceType::detect(1024, 1366, true))->toBe(JudgeDeviceType::Tablet)
        ->and(JudgeDeviceType::detect(1920, 1080, false))->toBe(JudgeDeviceType::Computer)
        ->and(JudgeDeviceType::detect(1366, 768, false))->toBe(JudgeDeviceType::Computer);
});

test('the one-third checkpoint opens once, after the save that reaches it, carrying the detected device', function () {
    $user = User::factory()->create(['first_name' => 'Ana']);
    actingAs($user);
    ['version' => $version, 'roomJudge' => $roomJudge, 'factor' => $factor, 'candidates' => $candidates] = makeCheckpointScenario($user, 6);

    $component = Livewire::test(Adjudicate::class, ['version' => $version])
        ->call('reportDevice', 390, 844, true);

    scoreCandidates($component, $factor, $candidates->take(1))
        ->assertSet('showCheckpoint', false);

    scoreCandidates($component, $factor, $candidates->slice(1, 1))
        ->assertSet('showCheckpoint', true)
        ->assertSet('checkpoint', JudgeCheckpoint::First->value)
        ->assertSee('End of the exposition')
        ->assertSee('Anything getting in the way?');

    $response = JudgeSurveyResponse::sole();
    expect($response->room_judge_id)->toBe($roomJudge->id)
        ->and($response->checkpoint)->toBe(JudgeCheckpoint::First)
        ->and($response->completed_count)->toBe(2)
        ->and($response->roster_count)->toBe(6)
        ->and($response->device_type)->toBe(JudgeDeviceType::Phone)
        ->and($response->screen_width)->toBe(390)
        ->and($component->get('checkpointMessage'))->toContain('Ana');
});

test('correcting an already-scored candidate never triggers a checkpoint', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'factor' => $factor, 'candidates' => $candidates] = makeCheckpointScenario($user, 6);

    $component = scoreCandidates(Livewire::test(Adjudicate::class, ['version' => $version]), $factor, $candidates->take(1));

    // Re-saving candidate 1 leaves the count at 1; only a new full sheet moves it.
    scoreCandidates($component, $factor, $candidates->take(1))
        ->assertSet('showCheckpoint', false);

    expect(JudgeSurveyResponse::count())->toBe(0);
});

test('submitting stores only the checkpoint\'s own questions, and the checkpoint never reopens', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'factor' => $factor, 'candidates' => $candidates] = makeCheckpointScenario($user, 6);

    $component = scoreCandidates(Livewire::test(Adjudicate::class, ['version' => $version]), $factor, $candidates->take(2))
        ->set('focus', JudgeFocus::Steady->value)
        ->set('obstacles', [JudgeObstacle::AudioPlayback->value, JudgeObstacle::PageSpeed->value])
        ->set('listeningDevice', ListeningDevice::Headphones->value)
        ->set('overallRating', 4)
        ->set('surveyComment', '  Loading the next candidate is slow.  ')
        ->call('submitCheckpoint')
        ->assertHasNoErrors()
        ->assertSet('showCheckpoint', false);

    $response = JudgeSurveyResponse::sole();
    expect($response->submitted_at)->not->toBeNull()
        ->and($response->dismissed_at)->toBeNull()
        ->and($response->focus)->toBe(JudgeFocus::Steady)
        ->and($response->obstacles)->toBe(['audio_playback', 'page_speed'])
        ->and($response->listening_device)->toBeNull()
        ->and($response->overall_rating)->toBeNull()
        ->and($response->comment)->toBe('Loading the next candidate is slow.');

    // A fresh page load (reload) at the same count doesn't bring it back.
    Livewire::test(Adjudicate::class, ['version' => $version])->assertSet('showCheckpoint', false);
    expect(JudgeSurveyResponse::count())->toBe(1);
});

test('the two-thirds and final checkpoints ask their own questions', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'factor' => $factor, 'candidates' => $candidates] = makeCheckpointScenario($user, 6);

    $component = Livewire::test(Adjudicate::class, ['version' => $version]);
    scoreCandidates($component, $factor, $candidates->take(2))->call('dismissCheckpoint');

    scoreCandidates($component, $factor, $candidates->slice(2, 2))
        ->assertSet('checkpoint', JudgeCheckpoint::Second->value)
        ->assertSee('What are you listening on?')
        ->set('listeningDevice', ListeningDevice::BuiltInSpeakers->value)
        ->call('submitCheckpoint');

    scoreCandidates($component, $factor, $candidates->slice(4, 1))
        ->assertSet('checkpoint', JudgeCheckpoint::Final->value)
        ->assertSee('Would you judge for this event again?')
        ->set('overallRating', 5)
        ->set('judgeAgain', 'yes')
        ->set('surveyComment', 'A keyboard shortcut for Next.')
        ->call('submitCheckpoint');

    // Finishing the roster thanks the judge instead of asking again.
    scoreCandidates($component, $factor, $candidates->slice(5, 1))
        ->assertSet('showCheckpoint', false);

    $rows = JudgeSurveyResponse::get()->keyBy(fn (JudgeSurveyResponse $row): string => (string) $row->getRawOriginal('checkpoint'));
    expect($rows)->toHaveCount(3)
        ->and($rows['first']->dismissed_at)->not->toBeNull()
        ->and($rows['first']->submitted_at)->toBeNull()
        ->and($rows['second']->listening_device)->toBe(ListeningDevice::BuiltInSpeakers)
        ->and($rows['final']->overall_rating)->toBe(5)
        ->and($rows['final']->getRawOriginal('judge_again'))->toBe('yes')
        ->and($rows['final']->comment)->toBe('A keyboard shortcut for Next.');
});

test('a blank submission counts as a dismissal', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'factor' => $factor, 'candidates' => $candidates] = makeCheckpointScenario($user, 6);

    scoreCandidates(Livewire::test(Adjudicate::class, ['version' => $version]), $factor, $candidates->take(2))
        ->set('surveyComment', '   ')
        ->call('submitCheckpoint')
        ->assertSet('showCheckpoint', false);

    $response = JudgeSurveyResponse::sole();
    expect($response->submitted_at)->toBeNull()
        ->and($response->dismissed_at)->not->toBeNull();
});

test('invalid answers are rejected', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'factor' => $factor, 'candidates' => $candidates] = makeCheckpointScenario($user, 6);

    scoreCandidates(Livewire::test(Adjudicate::class, ['version' => $version]), $factor, $candidates->take(2))
        ->set('focus', 'ecstatic')
        ->call('submitCheckpoint')
        ->assertHasErrors('focus')
        ->assertSet('showCheckpoint', true);
});

test('no checkpoint opens when check-ins are turned off for the Version', function () {
    $user = User::factory()->create();
    actingAs($user);
    ['version' => $version, 'factor' => $factor, 'candidates' => $candidates] = makeCheckpointScenario($user, 6);
    $version->update(['judge_survey' => false]);

    scoreCandidates(Livewire::test(Adjudicate::class, ['version' => $version->fresh()]), $factor, $candidates->take(5))
        ->assertSet('showCheckpoint', false);

    expect(JudgeSurveyResponse::count())->toBe(0);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeSurveyResponse(Version $version, array $attributes = []): JudgeSurveyResponse
{
    $roomJudge = $attributes['room_judge_id'] ?? RoomJudge::factory()->create(['version_id' => $version->id])->id;
    unset($attributes['room_judge_id']);

    return JudgeSurveyResponse::create([
        'version_id' => $version->id,
        'room_judge_id' => $roomJudge,
        'checkpoint' => JudgeCheckpoint::First,
        'completed_count' => 10,
        'roster_count' => 30,
        ...$attributes,
    ]);
}

test('the summary hides answers until enough judges have answered', function () {
    $version = Version::factory()->create();
    makeSurveyResponse($version, ['focus' => JudgeFocus::Fresh, 'comment' => 'Great', 'submitted_at' => now()]);
    makeSurveyResponse($version, ['focus' => JudgeFocus::Tiring, 'submitted_at' => now()]);
    makeSurveyResponse($version, ['dismissed_at' => now()]);

    $summary = app(JudgeSurveySummaryService::class)->forVersion($version);

    expect($summary['shown'])->toBe(3)
        ->and($summary['answered'])->toBe(2)
        ->and($summary['dismissed'])->toBe(1)
        ->and($summary['responseRate'])->toBe(67)
        ->and($summary['respondents'])->toBe(2)
        ->and($summary['visible'])->toBeFalse();
});

test('the summary aggregates focus by checkpoint, answers, devices per judge and comments', function () {
    $version = Version::factory()->create();
    $judgeA = RoomJudge::factory()->create(['version_id' => $version->id])->id;
    $judgeB = RoomJudge::factory()->create(['version_id' => $version->id])->id;
    $judgeC = RoomJudge::factory()->create(['version_id' => $version->id])->id;

    makeSurveyResponse($version, ['room_judge_id' => $judgeA, 'focus' => JudgeFocus::Fresh, 'obstacles' => ['audio_playback'], 'device_type' => JudgeDeviceType::Phone, 'submitted_at' => now()]);
    makeSurveyResponse($version, ['room_judge_id' => $judgeA, 'checkpoint' => JudgeCheckpoint::Second, 'focus' => JudgeFocus::Tiring, 'listening_device' => ListeningDevice::Headphones, 'device_type' => JudgeDeviceType::Phone, 'submitted_at' => now()]);
    makeSurveyResponse($version, ['room_judge_id' => $judgeA, 'checkpoint' => JudgeCheckpoint::Final, 'overall_rating' => 4, 'judge_again' => 'yes', 'device_type' => JudgeDeviceType::Computer, 'comment' => 'More breaks', 'submitted_at' => now()]);
    makeSurveyResponse($version, ['room_judge_id' => $judgeB, 'focus' => JudgeFocus::Steady, 'obstacles' => ['audio_playback', 'page_speed'], 'device_type' => JudgeDeviceType::Computer, 'submitted_at' => now()]);
    makeSurveyResponse($version, ['room_judge_id' => $judgeB, 'checkpoint' => JudgeCheckpoint::Final, 'overall_rating' => 3, 'judge_again' => 'maybe', 'submitted_at' => now()]);
    makeSurveyResponse($version, ['room_judge_id' => $judgeC, 'focus' => JudgeFocus::Fresh, 'device_type' => JudgeDeviceType::Tablet, 'submitted_at' => now()]);

    $summary = app(JudgeSurveySummaryService::class)->forVersion($version);

    expect($summary['visible'])->toBeTrue()
        ->and($summary['respondents'])->toBe(3)
        ->and($summary['checkpoints'][0]['focus'])->toBe(['fresh' => 2, 'steady' => 1, 'tiring' => 0, 'running_on_empty' => 0])
        ->and($summary['checkpoints'][1]['focus']['tiring'])->toBe(1)
        ->and($summary['obstacles'])->toBe(['respondents' => 2, 'counts' => ['audio_playback' => 2, 'page_speed' => 1, 'rubric_layout' => 0, 'recording_quality' => 0, 'nothing' => 0]])
        ->and($summary['listening']['counts']['headphones'])->toBe(1)
        ->and($summary['devices'])->toBe(['judges' => 3, 'counts' => ['phone' => 1, 'tablet' => 1, 'computer' => 1]])
        ->and($summary['overall']['average'])->toBe(3.5)
        ->and($summary['judgeAgain']['counts'])->toBe(['yes' => 1, 'maybe' => 1, 'no' => 0])
        ->and(collect($summary['comments'])->pluck('text')->all())->toBe(['More breaks']);
});

test('the summary is absent for a Version with check-ins off and no responses', function () {
    $version = Version::factory()->create(['judge_survey' => false]);

    expect(app(JudgeSurveySummaryService::class)->forVersion($version))->toBeNull();
});

test('the Scorecard Adjudication tab shows the check-in summary, and the panel never names a judge', function () {
    $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Active]);
    $eventManager = User::factory()->create();
    app(VersionRoleService::class)->withVersion($version, fn () => $eventManager->assignRole('Event Manager'));

    $judgeUser = User::factory()->create(['first_name' => 'Zebulon', 'last_name' => 'Quist']);
    makeSurveyResponse($version, ['room_judge_id' => RoomJudge::factory()->create(['version_id' => $version->id, 'user_id' => $judgeUser->id])->id, 'focus' => JudgeFocus::Fresh, 'comment' => 'Audio cut out twice', 'submitted_at' => now()]);
    makeSurveyResponse($version, ['focus' => JudgeFocus::Steady, 'submitted_at' => now()]);
    makeSurveyResponse($version, ['focus' => JudgeFocus::Tiring, 'submitted_at' => now()]);

    Livewire::actingAs($eventManager)
        ->test(VersionScorecard::class, ['version' => $version])
        ->set('tab', 'adjudication')
        ->assertSee('Judge check-ins')
        ->assertSee('Focus as judging goes on')
        ->assertSee('Audio cut out twice');

    // The Rooms section lists judges by name by design; the check-in panel itself never does.
    $panel = view('livewire.events.version-scorecard-judge-survey', ['survey' => app(JudgeSurveySummaryService::class)->forVersion($version)])->render();
    expect($panel)->toContain('Audio cut out twice')
        ->and($panel)->not->toContain('Quist')
        ->and($panel)->not->toContain('Zebulon');
});
