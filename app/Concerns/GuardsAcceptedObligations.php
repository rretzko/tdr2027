<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Version;
use App\Models\VersionInvitation;

trait GuardsAcceptedObligations
{
    /**
     * A rejected response is treated identically to no response at all —
     * every version-scoped page other than the obligations form itself
     * redirects there until the teacher accepts (or re-accepts). Returns
     * true (having already issued the redirect) when the caller's mount()
     * should stop short of loading the rest of the page.
     */
    private function redirectUnlessObligationsAccepted(Version $version, ?VersionInvitation $invitation): bool
    {
        $obligation = $version->obligation;

        if (! $obligation?->isPublished()) {
            return false;
        }

        if ($invitation?->obligationResponse?->isAccepted() === true) {
            return false;
        }

        $this->redirect(route('registrations.obligations', $version), navigate: true);

        return true;
    }
}
