<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Only two states — a rejection deletes the row outright (see
 * CandidateDetail::rejectRecording()) rather than persisting as a third
 * status, per event-version-orientation.md §5.2.
 */
enum CandidateUploadStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
        };
    }
}
