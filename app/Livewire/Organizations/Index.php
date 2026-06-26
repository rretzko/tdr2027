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
                $path = $this->membershipCards[$rootOrgId]->store('memberships/cards', 'public');
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
