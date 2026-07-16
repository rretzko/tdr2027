<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\VersionInvitationRequestStatus;
use App\Mail\VersionInvitationRequestApprovedMail;
use App\Models\User;
use App\Models\VersionInvitationRequest;
use App\Services\VersionInvitationRequestService;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Signed, unauthenticated approve/deny actions clicked from the Event
 * Manager's own inbox — see docs/plans/event-version-orientation.md §5.8.
 * The {user} route segment is the specific Event Manager the email was sent
 * to (each recipient gets their own personalized links), so decided_by can
 * be attributed without an active session.
 */
class VersionInvitationRequestController extends Controller
{
    public function approve(
        VersionInvitationRequest $versionInvitationRequest,
        User $user,
        VersionInvitationRequestService $requests,
        VersionRoleAssignmentService $roles,
    ): View {
        $this->authorizeDecidingUser($versionInvitationRequest, $user, $roles);

        if ($this->alreadyDecided($versionInvitationRequest)) {
            return $this->alreadyDecidedView($versionInvitationRequest);
        }

        $invitation = $requests->approve($versionInvitationRequest, $user);

        if ($invitation->teacher->user->email !== null) {
            Mail::to($invitation->teacher->user->email)
                ->send(new VersionInvitationRequestApprovedMail($versionInvitationRequest->version));
        }

        return view('version-invitation-requests.approved', [
            'versionInvitationRequest' => $versionInvitationRequest,
        ]);
    }

    public function deny(
        VersionInvitationRequest $versionInvitationRequest,
        User $user,
        VersionInvitationRequestService $requests,
        VersionRoleAssignmentService $roles,
    ): View {
        $this->authorizeDecidingUser($versionInvitationRequest, $user, $roles);

        if ($this->alreadyDecided($versionInvitationRequest)) {
            return $this->alreadyDecidedView($versionInvitationRequest);
        }

        $requests->deny($versionInvitationRequest, $user);

        return view('version-invitation-requests.denied', [
            'versionInvitationRequest' => $versionInvitationRequest,
        ]);
    }

    private function authorizeDecidingUser(VersionInvitationRequest $versionInvitationRequest, User $user, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent($user, $versionInvitationRequest->version->event), 403);
    }

    private function alreadyDecided(VersionInvitationRequest $versionInvitationRequest): bool
    {
        return $versionInvitationRequest->getRawOriginal('status') !== VersionInvitationRequestStatus::Pending->value;
    }

    private function alreadyDecidedView(VersionInvitationRequest $versionInvitationRequest): View
    {
        return view('version-invitation-requests.already-decided', [
            'versionInvitationRequest' => $versionInvitationRequest,
        ]);
    }
}
