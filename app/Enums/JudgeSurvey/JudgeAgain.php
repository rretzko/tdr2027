<?php

declare(strict_types=1);

namespace App\Enums\JudgeSurvey;

/** "Would you judge for this event again?" — the final checkpoint. */
enum JudgeAgain: string
{
    case Yes = 'yes';
    case Maybe = 'maybe';
    case No = 'no';

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Yes',
            self::Maybe => 'Maybe',
            self::No => 'No',
        };
    }
}
