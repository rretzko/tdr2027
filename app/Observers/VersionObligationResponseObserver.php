<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ObligationDecision;
use App\Enums\VersionInvitationStatus;
use App\Models\VersionObligationResponse;
use App\Services\CandidateService;

class VersionObligationResponseObserver
{
    public function saved(VersionObligationResponse $response): void
    {
        if (! $response->wasRecentlyCreated && ! $response->wasChanged('decision')) {
            return;
        }

        // getRawOriginal() lags by one save in a "saved" hook (syncOriginal()
        // runs after the event fires) — read the just-written value directly.
        $decision = $response->getAttributes()['decision'];

        $invitation = $response->versionInvitation;

        if ($decision === ObligationDecision::Accepted->value) {
            $invitation->update(['status' => VersionInvitationStatus::Obligated->value]);

            return;
        }

        if ($decision === ObligationDecision::Rejected->value) {
            $invitation->update(['status' => VersionInvitationStatus::Rejected->value]);

            app(CandidateService::class)->withdrawAllForTeacherVersion(
                $invitation->version_id,
                $invitation->teacher_id,
            );
        }
    }
}
