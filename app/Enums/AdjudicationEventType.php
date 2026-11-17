<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a judge did on the Adjudicate page. Score saves aren't logged here —
 * the scores table's own timestamps already record them.
 */
enum AdjudicationEventType: string
{
    case CandidateOpened = 'candidate_opened';
    case RecordingPlayed = 'recording_played';
    case RecordingPaused = 'recording_paused';
    case RecordingEnded = 'recording_ended';

    /**
     * The events the browser reports from an <audio> element.
     *
     * @return list<self>
     */
    public static function playbackEvents(): array
    {
        return [self::RecordingPlayed, self::RecordingPaused, self::RecordingEnded];
    }
}
