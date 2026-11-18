<?php

declare(strict_types=1);

use App\Enums\AdjudicationEventType;
use App\Support\Adjudication\ListeningEstimate;
use App\Support\Adjudication\PlaybackEvent;
use Illuminate\Support\Carbon;

function listenAt(AdjudicationEventType $type, string $time, ?int $recordingId = null, ?int $position = null): PlaybackEvent
{
    return new PlaybackEvent($type, $recordingId, $position, Carbon::parse("2026-11-17 {$time}"));
}

test('a recording played to the end before saving is fully heard', function () {
    $events = [
        listenAt(AdjudicationEventType::CandidateOpened, '14:00:00'),
        listenAt(AdjudicationEventType::RecordingPlayed, '14:00:02', 7, 0),
        listenAt(AdjudicationEventType::RecordingEnded, '14:00:42', 7, 40),
    ];

    expect(ListeningEstimate::heardRatio($events, Carbon::parse('2026-11-17 14:00:50'), [7 => 40]))->toBe(1.0);
});

test('a recording still playing at save time counts up to the save', function () {
    // Played from 0 at 14:00:02 and saved 20 seconds later; it ended after the save, which doesn't count.
    $events = [
        listenAt(AdjudicationEventType::CandidateOpened, '14:00:00'),
        listenAt(AdjudicationEventType::RecordingPlayed, '14:00:02', 7, 0),
        listenAt(AdjudicationEventType::RecordingEnded, '14:00:42', 7, 40),
    ];

    expect(ListeningEstimate::heardRatio($events, Carbon::parse('2026-11-17 14:00:22'), [7 => 40]))->toBe(0.5);
});

test('pauses keep the furthest point, and every Room recording counts toward the share', function () {
    $events = [
        listenAt(AdjudicationEventType::CandidateOpened, '14:00:00'),
        listenAt(AdjudicationEventType::RecordingPlayed, '14:00:01', 7, 0),
        listenAt(AdjudicationEventType::RecordingPaused, '14:00:31', 7, 30),
        listenAt(AdjudicationEventType::RecordingPlayed, '14:00:35', 7, 10), // replayed a passage
        listenAt(AdjudicationEventType::RecordingPaused, '14:00:40', 7, 15),
    ];

    // 30 of recording 7's 40s, none of recording 8's 60s.
    expect(ListeningEstimate::heardRatio($events, Carbon::parse('2026-11-17 14:01:00'), [7 => 40, 8 => 60]))->toBe(0.3);
});

test('a save is unmeasured without a tracked open before it or without known lengths', function () {
    $playedOnly = [listenAt(AdjudicationEventType::RecordingPlayed, '14:00:02', 7, 0)];
    $openedLater = [listenAt(AdjudicationEventType::CandidateOpened, '15:00:00')];
    $opened = [listenAt(AdjudicationEventType::CandidateOpened, '14:00:00')];
    $savedAt = Carbon::parse('2026-11-17 14:01:00');

    expect(ListeningEstimate::heardRatio($playedOnly, $savedAt, [7 => 40]))->toBeNull()
        ->and(ListeningEstimate::heardRatio($openedLater, $savedAt, [7 => 40]))->toBeNull()
        ->and(ListeningEstimate::heardRatio($opened, $savedAt, []))->toBeNull()
        // Opened but never played: measured, and nothing heard.
        ->and(ListeningEstimate::heardRatio($opened, $savedAt, [7 => 40]))->toBe(0.0);
});
