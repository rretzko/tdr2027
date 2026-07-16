<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\EventStatus;
use App\Models\VersionInvitation;
use App\Services\AutoEnrollmentService;

class VersionInvitationObserver
{
    /**
     * A new invitation (whether Event-Manager-initiated, §5.4, or approved
     * via a teacher's self-service request, §5.8) proactively enrolls every
     * already-eligible student the teacher has, rather than waiting for a
     * manual "Enroll a Student" action. Scoped to Active Versions only —
     * an invitation issued while the Version is still Sandbox shouldn't
     * silently populate real Candidate data before an Event Manager has
     * actually opened it.
     */
    public function created(VersionInvitation $invitation): void
    {
        $version = $invitation->version;

        if ($version->getRawOriginal('status') !== EventStatus::Active->value) {
            return;
        }

        app(AutoEnrollmentService::class)->enrollEligibleStudentsForVersion($version, $invitation->teacher);
    }
}
