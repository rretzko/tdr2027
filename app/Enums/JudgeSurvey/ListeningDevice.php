<?php

declare(strict_types=1);

namespace App\Enums\JudgeSurvey;

/** "What are you listening on?" — the second checkpoint's question. */
enum ListeningDevice: string
{
    case Headphones = 'headphones';
    case ExternalSpeakers = 'external_speakers';
    case BuiltInSpeakers = 'built_in_speakers';
    case PhoneSpeaker = 'phone_speaker';

    public function label(): string
    {
        return match ($this) {
            self::Headphones => 'Headphones or earbuds',
            self::ExternalSpeakers => 'External speakers',
            self::BuiltInSpeakers => 'Computer or tablet speakers',
            self::PhoneSpeaker => 'Phone speaker',
        };
    }
}
