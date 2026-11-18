<?php

declare(strict_types=1);

namespace App\Support\Adjudication;

use App\Enums\AdjudicationEventType;
use Illuminate\Support\Carbon;

/**
 * One adjudication_events row, as ListeningEstimate reads it.
 */
final readonly class PlaybackEvent
{
    public function __construct(
        public AdjudicationEventType $event,
        public ?int $recordingId,
        public ?int $positionSeconds,
        public Carbon $at,
    ) {}
}
