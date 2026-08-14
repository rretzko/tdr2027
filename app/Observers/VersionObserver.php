<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\EventStatus;
use App\Models\Version;
use App\Services\AutoEnrollmentService;

class VersionObserver
{
    /**
     * When a Version transitions into Active, backfill enrollment for every
     * teacher who already holds an invitation on it — VersionInvitationObserver
     * skips enrollment for invitations created while the Version was still
     * Sandbox (including every invitation VersionCloningService clones in,
     * since a clone always starts life as Sandbox), and nothing else
     * re-triggers it once Active is set via a plain attribute update.
     */
    public function updated(Version $version): void
    {
        if (! $version->wasChanged('status')) {
            return;
        }

        // Not getRawOriginal() here — by the time `updated` fires,
        // syncChanges() has already run but syncOriginal() hasn't (it runs
        // later, in finishSave()), so getRawOriginal() would still read the
        // *pre-update* value. getAttributes() reads the current raw value.
        if ($version->getAttributes()['status'] !== EventStatus::Active->value) {
            return;
        }

        app(AutoEnrollmentService::class)->enrollAllInvitedTeachersForVersion($version);
    }
}
