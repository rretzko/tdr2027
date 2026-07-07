<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\School;
use App\Models\Teacher;
use App\Models\VersionInvitation;
use Carbon\Carbon;

final readonly class VersionInvitationRosterRow
{
    public function __construct(
        public Teacher $teacher,
        public ?School $school,
        public ?Carbon $membershipExpiresAt,
        public string $status,
        public ?VersionInvitation $invitation,
    ) {}
}
