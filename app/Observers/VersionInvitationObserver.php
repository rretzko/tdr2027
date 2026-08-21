<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\EventStatus;
use App\Models\VersionInvitation;
use App\Services\AutoEnrollmentService;
use App\Services\VersionRoleAssignmentService;

class VersionInvitationObserver
{
    /**
     * A new invitation (whether Event-Manager-initiated, §5.4, or approved
     * via a teacher's self-service request, §5.8) proactively enrolls every
     * already-eligible student the teacher has, rather than waiting for a
     * manual "Enroll a Student" action. Scoped to Active Versions — an
     * invitation issued while the Version is still Sandbox shouldn't
     * silently populate real Candidate data before an Event Manager has
     * actually opened it — with one exception: an invited teacher who is
     * themselves the Event Manager for this Version's Event still gets
     * enrolled, since they need real candidate data to preview how
     * Registrations will behave for their own teachers before flipping the
     * Version to Active (see Registrations\Index's matching Sandbox
     * visibility carve-out).
     */
    public function created(VersionInvitation $invitation): void
    {
        $version = $invitation->version;

        $isActive = $version->getRawOriginal('status') === EventStatus::Active->value;
        $invitedUser = $invitation->teacher->user;
        $isPreviewingEventManager = $invitedUser !== null
            && app(VersionRoleAssignmentService::class)->isEventManagerForEvent($invitedUser, $version->event);

        if (! $isActive && ! $isPreviewingEventManager) {
            return;
        }

        app(AutoEnrollmentService::class)->enrollEligibleStudentsForVersion($version, $invitation->teacher);
    }
}
