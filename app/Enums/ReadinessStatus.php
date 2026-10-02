<?php

declare(strict_types=1);

namespace App\Enums;

enum ReadinessStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case NeedsReview = 'needs_review';
    case Done = 'done';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::NeedsReview => 'Needs review',
            self::Done => 'Done',
            self::NotApplicable => 'Not applicable',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotStarted => 'red',
            self::InProgress => 'amber',
            self::NeedsReview => 'sky',
            self::Done => 'green',
            self::NotApplicable => 'zinc',
        };
    }

    /**
     * Missing data — the only statuses that can block a phase gate.
     * NeedsReview deliberately does not block (version-readiness.md §9a Q2).
     */
    public function isIncomplete(): bool
    {
        return $this === self::NotStarted || $this === self::InProgress;
    }

    public function isComplete(): bool
    {
        return $this === self::Done || $this === self::NotApplicable;
    }
}
