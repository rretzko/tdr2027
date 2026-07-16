<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Mail\VersionInvitationRequestSubmittedMail;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionInvitationRequest;
use App\Services\VersionInvitationEligibilityService;
use App\Services\VersionInvitationRequestService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class RequestInvitation extends Component
{
    public Version $version;

    public function mount(Version $version, VersionInvitationEligibilityService $eligibility): void
    {
        $teacher = $this->teacher();

        $alreadyInvited = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->exists();

        if ($alreadyInvited) {
            $this->redirect(route('registrations.version', $version), navigate: true);

            return;
        }

        abort_unless($eligibility->isEligible($version, $teacher), 403);

        $this->version = $version;
    }

    public function request(VersionInvitationRequestService $requests, VersionRoleAssignmentService $roles, VersionInvitationEligibilityService $eligibility): void
    {
        $teacher = $this->teacher();

        if (! $requests->canRequest($this->version, $teacher)) {
            Flux::toast(text: 'This request can\'t be submitted right now.', variant: 'warning');

            return;
        }

        $versionInvitationRequest = $requests->request($this->version, $teacher);

        $this->notifyEventManagers($versionInvitationRequest, $roles, $eligibility);

        Flux::toast(text: 'Invitation requested — the Event Manager has been notified.', variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.registrations.request-invitation', [
            'existingRequest' => $this->existingRequest(),
        ]);
    }

    private function existingRequest(): ?VersionInvitationRequest
    {
        return VersionInvitationRequest::where('version_id', $this->version->id)
            ->where('teacher_id', $this->teacher()->id)
            ->first();
    }

    private function notifyEventManagers(
        VersionInvitationRequest $versionInvitationRequest,
        VersionRoleAssignmentService $roles,
        VersionInvitationEligibilityService $eligibility,
    ): void {
        $teacher = $this->teacher();
        $row = $eligibility->roster($this->version)->first(fn ($r): bool => $r->teacher->id === $teacher->id);

        $schoolName = $row?->school?->name;
        $countyName = $row?->school?->county?->name;
        $membershipExpiresAt = $row?->membershipExpiresAt?->format('M j, Y');
        $membershipNumber = $teacher->memberships
            ->firstWhere('organization_id', $this->version->event->organization->membershipOrganization()->id)
            ?->membership_number;

        $expiresAt = now()->addDays(7);

        foreach ($roles->eventManagersForEvent($this->version->event) as $eventManager) {
            if ($eventManager->email === null) {
                continue;
            }

            $approveUrl = URL::temporarySignedRoute(
                'version-invitation-requests.approve',
                $expiresAt,
                ['versionInvitationRequest' => $versionInvitationRequest->id, 'user' => $eventManager->id],
            );
            $denyUrl = URL::temporarySignedRoute(
                'version-invitation-requests.deny',
                $expiresAt,
                ['versionInvitationRequest' => $versionInvitationRequest->id, 'user' => $eventManager->id],
            );

            Mail::to($eventManager->email)->send(new VersionInvitationRequestSubmittedMail(
                $teacher,
                $this->version,
                $schoolName,
                $countyName,
                $membershipNumber,
                $membershipExpiresAt,
                $approveUrl,
                $denyUrl,
                $expiresAt,
            ));
        }
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
