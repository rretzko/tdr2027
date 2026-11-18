<?php

declare(strict_types=1);

namespace App\Support\Adjudication;

use App\Enums\AdjudicationEventType;

/**
 * One adjudication_events row, as ListeningEstimate reads it. $at is a Unix
 * timestamp rather than a Carbon: an all-state Version can have tens of
 * thousands of these per Scorecard refresh, and parsing each into a Carbon
 * was a measurable share of the cost.
 */
final readonly class PlaybackEvent
{
    public function __construct(
        public AdjudicationEventType $event,
        public ?int $recordingId,
        public ?int $positionSeconds,
        public int $at,
    ) {}
}
