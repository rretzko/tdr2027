<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who may change the setting behind a readiness item — mirrors the gate on
 * the page the item's "Open" link leads to, so the checklist can explain
 * a locked item instead of sending the viewer to a 403.
 */
enum ReadinessEditor: string
{
    /** VersionRoleAssignmentService::canManageEvent() — Configure, Invitations, Pitch Files, Ensembles. */
    case EventManager = 'event_manager';

    /** canManageAuditionEnvironment() — Rooms, Scoring Rubric. */
    case AuditionEnvironment = 'audition_environment';

    /** canManageCoRegistrationManagers() — Co-Registration Managers. */
    case RegistrationManager = 'registration_manager';

    public function description(): string
    {
        return match ($this) {
            self::EventManager => 'an Event Manager',
            self::AuditionEnvironment => 'an Event Manager, Registration Manager, or Co-Registration Manager',
            self::RegistrationManager => 'an Event Manager or the Registration Manager',
        };
    }
}
