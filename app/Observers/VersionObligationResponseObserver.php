<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ObligationDecision;
use App\Enums\VersionInvitationStatus;
use App\Models\VersionObligationResponse;

class VersionObligationResponseObserver
{
    public function saved(VersionObligationResponse $response): void
    {
        if (! $response->wasRecentlyCreated && ! $response->wasChanged('decision')) {
            return;
        }

        // getRawOriginal() lags by one save in a "saved" hook (syncOriginal()
        // runs after the event fires) — read the just-written value directly.
        if ($response->getAttributes()['decision'] !== ObligationDecision::Accepted->value) {
            return;
        }

        $response->versionInvitation()->update(['status' => VersionInvitationStatus::Obligated->value]);
    }
}
