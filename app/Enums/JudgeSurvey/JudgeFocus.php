<?php

declare(strict_types=1);

namespace App\Enums\JudgeSurvey;

/** "How's your focus right now?" — asked at every checkpoint, so it trends. */
enum JudgeFocus: string
{
    case Fresh = 'fresh';
    case Steady = 'steady';
    case Tiring = 'tiring';
    case RunningOnEmpty = 'running_on_empty';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Fresh',
            self::Steady => 'Steady',
            self::Tiring => 'Tiring',
            self::RunningOnEmpty => 'Running on empty',
        };
    }
}
