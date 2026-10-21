<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\VersionInvitationStatus;
use App\Models\Version;
use App\Models\VersionDate;

/**
 * One director's Wednesday participation update for a single Version, computed
 * by DirectorWeeklyUpdateService and rendered by DirectorWeeklyUpdateMail.
 * All fee amounts are cents; the *InDollars() helpers are for the email view
 * only.
 */
final readonly class DirectorWeeklyUpdate
{
    /**
     * @param  list<VersionDate>  $dates  Candidate, Postmark Deadline, and Adjudication dates, in calendar order
     * @param  list<array{name: string, voicePart: string, missing: list<string>}>  $pendingRoster
     * @param  list<array{name: string, voicePart: string}>  $registeredRoster
     */
    public function __construct(
        public Version $version,
        public VersionInvitationStatus $invitationStatus,
        public array $dates,
        public int $eligibleStudents,
        public int $pendingStudents,
        public int $registeredStudents,
        public int $registrationFeeCents,
        public int $participationFeeCents,
        public int $dueCents,
        public int $paidCents,
        public array $pendingRoster,
        public array $registeredRoster,
    ) {}

    public function balanceDueCents(): int
    {
        return $this->dueCents - $this->paidCents;
    }

    public function hasFees(): bool
    {
        return $this->registrationFeeCents > 0 || $this->participationFeeCents > 0;
    }

    public static function dollars(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2);
    }
}
