<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Version;
use App\Services\AutoEnrollmentService;

class VersionObserver
{
    public function created(Version $version): void
    {
        if ($version->getAttributes()['status'] === EventStatus::Active->value) {
            $this->promoteEvent($version);
        }
    }

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

        $this->promoteEvent($version);

        app(AutoEnrollmentService::class)->enrollAllInvitedTeachersForVersion($version);
    }

    /**
     * An Active Version under a non-Active Event is a contradiction — Event
     * status gates things like the onboarding wizard's open-Events list, so a
     * live Version under a Sandbox Event silently hides it. Event Managers can
     * activate a Version but can't edit the Event (Founder-only), so the
     * Event follows the Version up rather than blocking the activation.
     */
    private function promoteEvent(Version $version): void
    {
        $event = Event::find($version->event_id);

        if ($event === null || $event->getRawOriginal('status') === EventStatus::Active->value) {
            return;
        }

        $event->update(['status' => EventStatus::Active->value]);
    }
}
