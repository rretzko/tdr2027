<?php

declare(strict_types=1);

namespace App\Livewire\Organizations;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Pivots\TeacherSupervisor;
use App\Models\Teacher;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use WithFileUploads;

    /** @var list<int> */
    public array $selectedOrganizationIds = [];

    /** @var array<int, string> */
    public array $membershipNumber = [];

    /** @var array<int, string> */
    public array $membershipExpiresAt = [];

    /** @var array<int, string> */
    public array $existingMembershipCards = [];

    /** @var array<int, mixed> */
    public array $membershipCards = [];

    public function mount(): void
    {
        $teacher = $this->teacher();

        $this->selectedOrganizationIds = TeacherSupervisor::where('teacher_id', $teacher->id)
            ->pluck('organization_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        Membership::where('teacher_id', $teacher->id)
            ->get()
            ->each(function (Membership $m): void {
                $orgId = $m->organization_id;
                $this->membershipNumber[$orgId] = $m->membership_number ?? '';
                $this->membershipExpiresAt[$orgId] = $m->getRawOriginal('membership_expires_at') ?? '';
                if ($m->membership_card !== null) {
                    $this->existingMembershipCards[$orgId] = $m->membership_card;
                }
            });
    }

    public function save(): void
    {
        $this->validate([
            'membershipNumber.*' => ['nullable', 'string', 'max:100'],
            'membershipExpiresAt.*' => ['nullable', 'date'],
            'membershipCards.*' => ['nullable', 'image', 'max:4096'],
        ]);

        $teacherId = $this->teacher()->id;

        TeacherSupervisor::where('teacher_id', $teacherId)
            ->whereNotIn('organization_id', $this->selectedOrganizationIds)
            ->delete();

        foreach ($this->selectedOrganizationIds as $orgId) {
            TeacherSupervisor::firstOrCreate([
                'organization_id' => $orgId,
                'teacher_id' => $teacherId,
            ]);
        }

        $rootOrgIds = $this->selectedOrganizationIds === []
            ? []
            : Organization::whereIn('id', $this->selectedOrganizationIds)
                ->with('parent')
                ->get()
                ->map(fn (Organization $org) => $org->membershipOrganization()->id)
                ->unique()
                ->all();

        foreach ($rootOrgIds as $rootOrgId) {
            $data = [
                'membership_number' => ($this->membershipNumber[$rootOrgId] ?? '') ?: null,
                'membership_expires_at' => ($this->membershipExpiresAt[$rootOrgId] ?? '') ?: null,
            ];

            if (isset($this->membershipCards[$rootOrgId])) {
                // 's3' (private-by-default, signed temporaryUrl() reads), not
                // 'public' — matches every other uploaded document in this
                // app (recordings, pitch files, application PDFs, org
                // logos) and what EstimateFormData::resolveImageUrl()
                // already expects when it displays this same card.
                $path = $this->membershipCards[$rootOrgId]->store('memberships/cards', 's3');
                $data['membership_card'] = $path;
                $this->existingMembershipCards[$rootOrgId] = $path;
                $this->membershipCards[$rootOrgId] = null;
            }

            Membership::updateOrCreate(
                ['teacher_id' => $teacherId, 'organization_id' => $rootOrgId],
                $data,
            );
        }

        Flux::toast('Your organizations have been updated.');
    }

    /**
     * Immediate, not deferred to the next Save click — unlike a new upload
     * (queued in membershipCards[] until save()), a removal has nothing
     * left to stage; there's no "undo" state to preserve by waiting.
     * Scoped to the acting teacher's own Membership row regardless of which
     * $rootOrganizationId a tampered request passes — the query's own
     * teacher_id condition is the authorization boundary, not the caller.
     */
    public function removeMembershipCard(int $rootOrganizationId): void
    {
        $membership = Membership::where('teacher_id', $this->teacher()->id)
            ->where('organization_id', $rootOrganizationId)
            ->first();

        if ($membership?->membership_card !== null) {
            Storage::disk('s3')->delete($membership->membership_card);
            $membership->update(['membership_card' => null]);
        }

        unset($this->existingMembershipCards[$rootOrganizationId]);

        Flux::toast('Membership card removed.');
    }

    public function render(): View
    {
        return view('livewire.organizations.index', [
            'organizationTree' => $this->organizationTree(),
        ]);
    }

    /**
     * @return list<array{organization: Organization, children: array}>
     */
    private function organizationTree(): array
    {
        return $this->organizationChildrenOf(Organization::orderBy('name')->get(), null);
    }

    /**
     * @param  Collection<int, Organization>  $organizations
     * @return list<array{organization: Organization, children: array}>
     */
    private function organizationChildrenOf(Collection $organizations, ?int $parentId): array
    {
        return $organizations
            ->where('parent_id', $parentId)
            ->map(fn (Organization $organization) => [
                'organization' => $organization,
                'children' => $this->organizationChildrenOf($organizations, $organization->id),
            ])
            ->values()
            ->all();
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
