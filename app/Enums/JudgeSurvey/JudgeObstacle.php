<?php

declare(strict_types=1);

namespace App\Enums\JudgeSurvey;

/** "Anything getting in the way?" — the first checkpoint's question. */
enum JudgeObstacle: string
{
    case AudioPlayback = 'audio_playback';
    case PageSpeed = 'page_speed';
    case RubricLayout = 'rubric_layout';
    case RecordingQuality = 'recording_quality';
    case Nothing = 'nothing';

    public function label(): string
    {
        return match ($this) {
            self::AudioPlayback => 'Audio playback',
            self::PageSpeed => 'Page speed',
            self::RubricLayout => 'Rubric layout',
            self::RecordingQuality => 'Recording quality',
            self::Nothing => 'Nothing',
        };
    }
}
