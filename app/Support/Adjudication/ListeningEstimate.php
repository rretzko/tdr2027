<?php

declare(strict_types=1);

namespace App\Support\Adjudication;

use App\Enums\AdjudicationEventType;
use Illuminate\Support\Carbon;

/**
 * How much of a Candidate's recordings a judge had heard when they first
 * saved scores for them, replayed from adjudication_events.
 *
 * For each recording, a play followed by a pause or end gives the position
 * reached. A recording still playing when the score saved is taken to have
 * reached its play position plus the seconds since — needed because moving
 * on to the next Candidate removes the <audio> element without a pause or
 * end event. The measure is the furthest point reached, so skipping ahead
 * counts as heard.
 */
final class ListeningEstimate
{
    /**
     * @param  list<PlaybackEvent>  $events  this judge's events for this Candidate, ascending
     * @param  array<int, int>  $durations  recording id => length in seconds, for the Room's recordings of this Candidate whose length is known
     * @return ?float share of the recordings heard (0–1); null when the save can't be measured — no tracked open before it, or no known lengths
     */
    public static function heardRatio(array $events, Carbon $savedAt, array $durations): ?float
    {
        $total = array_sum($durations);

        if ($total <= 0) {
            return null;
        }

        $before = array_filter($events, fn (PlaybackEvent $event): bool => $event->at->lte($savedAt));

        $opened = array_filter($before, fn (PlaybackEvent $event): bool => $event->event === AdjudicationEventType::CandidateOpened);
        if ($opened === []) {
            return null;
        }

        $furthest = array_fill_keys(array_keys($durations), 0.0);
        /** @var array<int, PlaybackEvent> $playing */
        $playing = [];

        foreach ($before as $event) {
            $recordingId = $event->recordingId;

            if ($recordingId === null || ! array_key_exists($recordingId, $durations)) {
                continue;
            }

            if ($event->event === AdjudicationEventType::RecordingPlayed) {
                $playing[$recordingId] = $event;
            } elseif ($event->event === AdjudicationEventType::RecordingPaused || $event->event === AdjudicationEventType::RecordingEnded) {
                $furthest[$recordingId] = max($furthest[$recordingId], (float) ($event->positionSeconds ?? 0));
                unset($playing[$recordingId]);
            }
        }

        foreach ($playing as $recordingId => $play) {
            $furthest[$recordingId] = max($furthest[$recordingId], ($play->positionSeconds ?? 0) + $play->at->diffInSeconds($savedAt));
        }

        $heard = 0.0;
        foreach ($durations as $recordingId => $duration) {
            $heard += min((float) $duration, $furthest[$recordingId]);
        }

        return $heard / $total;
    }
}
