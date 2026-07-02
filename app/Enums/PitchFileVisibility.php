<?php

declare(strict_types=1);

namespace App\Enums;

enum PitchFileVisibility: string
{
    case Both = 'both';
    case Candidate = 'candidate';
    case Teacher = 'teacher';

    public function label(): string
    {
        return match ($this) {
            self::Both => 'Both (Teacher & Candidate)',
            self::Candidate => 'Candidate Only',
            self::Teacher => 'Teacher Only',
        };
    }
}
