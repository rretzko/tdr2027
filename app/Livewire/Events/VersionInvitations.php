<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\VersionInvitationStatus;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Services\VersionInvitationEligibilityService;
use App\Services\VersionRoleAssignmentService;
use App\Support\VersionInvitationRosterRow;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class VersionInvitations extends Component
{
    public Version $version;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    public string $sortColumn = 'teacher';

    public string $sortDirection = 'asc';

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $version->event), 403);

        $this->version = $version;
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
    }

    /**
     * Clicking a status stat tile filters to that status; clicking the
     * already-active tile clears the filter.
     */
    public function filterByStatus(string $status): void
    {
        $this->statusFilter = $this->statusFilter === $status ? '' : $status;
    }

    public function toggle(int $teacherId, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);

        $existing = VersionInvitation::where('version_id', $this->version->id)
            ->where('teacher_id', $teacherId)
            ->first();

        if ($existing === null) {
            $this->createInvitation($teacherId);
            Flux::toast(text: 'Teacher invited.', variant: 'success');

            return;
        }

        if (! $this->uninvite($existing)) {
            Flux::toast(text: 'Cannot remove this invitation — the teacher has already agreed to Version obligations.', variant: 'warning');

            return;
        }

        Flux::toast('Invitation removed.');
    }

    public function inviteAll(VersionInvitationEligibilityService $eligibility, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);

        $invited = 0;

        foreach ($eligibility->roster($this->version) as $row) {
            if ($row->invitation === null) {
                $this->createInvitation($row->teacher->id);
                $invited++;
            }
        }

        Flux::toast(text: $invited > 0 ? "{$invited} teacher(s) invited." : 'Everyone eligible is already invited.', variant: 'success');
    }

    public function removeAll(VersionInvitationEligibilityService $eligibility, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);

        $removed = 0;
        $blocked = 0;

        foreach ($eligibility->roster($this->version) as $row) {
            $invitation = $row->invitation;

            if ($invitation === null) {
                continue;
            }

            if ($this->uninvite($invitation)) {
                $removed++;
            } else {
                $blocked++;
            }
        }

        $message = "{$removed} invitation(s) removed.";

        if ($blocked > 0) {
            $message .= " {$blocked} teacher(s) skipped — already agreed to Version obligations.";
        }

        Flux::toast(text: $message, variant: $blocked > 0 ? 'warning' : 'success');
    }

    public function render(VersionInvitationEligibilityService $eligibility): View
    {
        $fullRoster = $eligibility->roster($this->version);

        return view('livewire.events.version-invitations', [
            'roster' => $this->filterAndSort($fullRoster),
            'hasEligibleTeachers' => $fullRoster->isNotEmpty(),
            'statusCounts' => $this->statusCounts($fullRoster),
        ]);
    }

    /**
     * @param  Collection<int, VersionInvitationRosterRow>  $roster
     * @return array<string, int>
     */
    private function statusCounts(Collection $roster): array
    {
        $counts = ['eligible' => 0, 'invited' => 0, 'obligated' => 0, 'participating' => 0];

        foreach ($roster as $row) {
            $counts[$row->status] = ($counts[$row->status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, VersionInvitationRosterRow>  $roster
     * @return Collection<int, VersionInvitationRosterRow>
     */
    private function filterAndSort(Collection $roster): Collection
    {
        $search = mb_strtolower(trim($this->search));

        if ($search !== '') {
            $roster = $roster->filter(fn (VersionInvitationRosterRow $row): bool => str_contains(mb_strtolower($row->teacher->user->name), $search)
                || str_contains(mb_strtolower($row->teacher->user->email), $search)
                || str_contains(mb_strtolower($this->schoolName($row)), $search));
        }

        if ($this->statusFilter !== '') {
            $roster = $roster->filter(fn (VersionInvitationRosterRow $row): bool => $row->status === $this->statusFilter);
        }

        $sortValue = fn (VersionInvitationRosterRow $row): string => match ($this->sortColumn) {
            'school' => mb_strtolower($this->schoolName($row)),
            default => mb_strtolower($row->teacher->user->sort_name),
        };

        $roster = $this->sortDirection === 'desc'
            ? $roster->sortByDesc($sortValue)
            : $roster->sortBy($sortValue);

        return $roster->values();
    }

    private function schoolName(VersionInvitationRosterRow $row): string
    {
        return $row->school !== null ? $row->school->name : '';
    }

    private function createInvitation(int $teacherId): void
    {
        VersionInvitation::create([
            'version_id' => $this->version->id,
            'teacher_id' => $teacherId,
            'status' => VersionInvitationStatus::Invited->value,
            'invited_at' => now(),
            'invited_by_user_id' => Auth::id(),
        ]);
    }

    /**
     * Deletes the invitation and returns true, unless the teacher has moved
     * past "invited" (obligated/participating), in which case it's left
     * alone and false is returned. See §5.4 guard note — not reachable yet
     * this phase, but must not silently no-op once it is.
     */
    private function uninvite(VersionInvitation $invitation): bool
    {
        $rawStatus = $invitation->getRawOriginal('status');

        if (in_array($rawStatus, [VersionInvitationStatus::Obligated->value, VersionInvitationStatus::Participating->value], true)) {
            return false;
        }

        $invitation->delete();

        return true;
    }
}
