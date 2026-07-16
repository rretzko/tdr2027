<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VersionInvitationRequestStatus;
use App\Enums\VersionInvitationStatus;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionInvitationRequest;
use RuntimeException;

/**
 * Teacher-initiated counterpart to the Event-Manager-driven roster in
 * VersionInvitationEligibilityService (§5.4). See
 * docs/plans/event-version-orientation.md §5.8.
 */
class VersionInvitationRequestService
{
    public function __construct(private readonly VersionInvitationEligibilityService $eligibility) {}

    /**
     * A teacher may request an invitation only if: they're in the computed
     * eligible pool (§5.4's OR logic — county match or org membership),
     * they don't already have a version_invitations row (they should go
     * straight to Registration instead), and they either have no request on
     * file or their prior request was denied — denial is not terminal
     * (§8.11).
     */
    public function canRequest(Version $version, Teacher $teacher): bool
    {
        $alreadyInvited = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->exists();

        if ($alreadyInvited) {
            return false;
        }

        $existing = $this->findRequest($version, $teacher);

        if ($existing !== null && $existing->getRawOriginal('status') !== VersionInvitationRequestStatus::Denied->value) {
            return false;
        }

        return $this->eligibility->isEligible($version, $teacher);
    }

    /**
     * Creates the request, or resets it to Pending if the prior one was
     * denied — the same row is toggled in place rather than accumulating
     * history (mirrors version_obligation_responses, §5.6).
     *
     * @throws RuntimeException if the teacher isn't allowed to request right now
     */
    public function request(Version $version, Teacher $teacher): VersionInvitationRequest
    {
        if (! $this->canRequest($version, $teacher)) {
            throw new RuntimeException('This teacher is not eligible to request an invitation for this Version right now.');
        }

        return VersionInvitationRequest::updateOrCreate(
            ['version_id' => $version->id, 'teacher_id' => $teacher->id],
            [
                'status' => VersionInvitationRequestStatus::Pending->value,
                'requested_at' => now(),
                'decided_at' => null,
                'decided_by_user_id' => null,
            ],
        );
    }

    /**
     * Approves a pending request and creates the actual version_invitations
     * row via the same shape as an Event-Manager-initiated invite (§5.4).
     *
     * @throws RuntimeException if the request isn't pending (already decided)
     */
    public function approve(VersionInvitationRequest $request, User $decidedBy): VersionInvitation
    {
        $this->assertPending($request);

        $request->update([
            'status' => VersionInvitationRequestStatus::Approved->value,
            'decided_at' => now(),
            'decided_by_user_id' => $decidedBy->id,
        ]);

        return VersionInvitation::updateOrCreate(
            ['version_id' => $request->version_id, 'teacher_id' => $request->teacher_id],
            [
                'status' => VersionInvitationStatus::Invited->value,
                'invited_at' => now(),
                'invited_by_user_id' => $decidedBy->id,
            ],
        );
    }

    /**
     * Denies a pending request. No email is sent server-side — the Event
     * Manager composes their own reason via a client-side mailto: link
     * (§5.8); the reason is never captured or persisted here.
     *
     * @throws RuntimeException if the request isn't pending (already decided)
     */
    public function deny(VersionInvitationRequest $request, User $decidedBy): void
    {
        $this->assertPending($request);

        $request->update([
            'status' => VersionInvitationRequestStatus::Denied->value,
            'decided_at' => now(),
            'decided_by_user_id' => $decidedBy->id,
        ]);
    }

    /**
     * @throws RuntimeException if the request isn't pending (already decided)
     */
    private function assertPending(VersionInvitationRequest $request): void
    {
        if ($request->getRawOriginal('status') !== VersionInvitationRequestStatus::Pending->value) {
            throw new RuntimeException('This request has already been decided.');
        }
    }

    private function findRequest(Version $version, Teacher $teacher): ?VersionInvitationRequest
    {
        return VersionInvitationRequest::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->first();
    }
}
