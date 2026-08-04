<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Models\CoRegistrationManagerCounty;
use App\Models\User;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class VersionCoRegistrationManagers extends Component
{
    public Version $version;

    public ?int $editingUserId = null;

    public string $search = '';

    public ?int $selectedUserId = null;

    /** @var list<int> */
    public array $countyIds = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageCoRegistrationManagers(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function add(): void
    {
        $this->editingUserId = null;
        $this->search = '';
        $this->selectedUserId = null;
        $this->countyIds = [];
        $this->resetErrorBag();
    }

    public function editCounties(int $userId): void
    {
        $this->editingUserId = $userId;
        $this->selectedUserId = $userId;
        $this->search = '';
        $this->countyIds = CoRegistrationManagerCounty::where('version_id', $this->version->id)
            ->where('user_id', $userId)
            ->pluck('county_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->resetErrorBag();
    }

    /**
     * @return Collection<int, User>
     */
    public function searchResults(): Collection
    {
        $term = trim($this->search);

        if ($term === '' || $this->selectedUserId !== null) {
            return new Collection;
        }

        return User::query()
            ->where('name', 'like', "%{$term}%")
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    public function selectUser(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->selectedUserId = $user->id;
        $this->search = $user->name;
    }

    public function clearSelection(): void
    {
        $this->selectedUserId = null;
        $this->search = '';
    }

    public function save(VersionRoleAssignmentService $roles): void
    {
        $assignableCountyIds = $this->assignableCountyIds();

        $validated = $this->validate([
            'selectedUserId' => ['required', 'integer', 'exists:users,id'],
            'countyIds' => ['array'],
            'countyIds.*' => ['integer', Rule::in($assignableCountyIds)],
        ]);

        $targetUser = User::findOrFail($validated['selectedUserId']);

        $roles->assignCoRegistrationManager(Auth::user(), $this->version, $targetUser, $validated['countyIds']);

        $this->editingUserId = null;
        $this->modal('co-registration-manager-form')->close();

        Flux::toast(text: "{$targetUser->name} assigned as Co-Registration Manager.", variant: 'success');
    }

    public function remove(int $userId, VersionRoleAssignmentService $roles): void
    {
        $targetUser = User::findOrFail($userId);

        $roles->revokeCoRegistrationManager(Auth::user(), $this->version, $targetUser);

        Flux::toast(text: "{$targetUser->name} removed as Co-Registration Manager.", variant: 'success');
    }

    /**
     * Counties eligible to appear on the form: the Version's own county
     * list, minus any county already claimed by a *different* manager on
     * this Version (mutual exclusivity) — the manager currently being
     * edited keeps their own already-assigned counties selectable.
     *
     * @return list<int>
     */
    private function assignableCountyIds(): array
    {
        $versionCountyIds = $this->version->counties->pluck('county_id')->map(fn ($id): int => (int) $id)->all();

        $takenByOthers = CoRegistrationManagerCounty::where('version_id', $this->version->id)
            ->when($this->editingUserId !== null, fn ($query) => $query->where('user_id', '!=', $this->editingUserId))
            ->pluck('county_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_diff($versionCountyIds, $takenByOthers));
    }

    public function render(): View
    {
        $assignments = CoRegistrationManagerCounty::where('version_id', $this->version->id)
            ->with(['user', 'county'])
            ->get()
            ->groupBy('user_id');

        return view('livewire.events.version-co-registration-managers', [
            'managers' => $assignments,
            'availableCounties' => $this->version->counties()->with('county')->get()
                ->pluck('county')
                ->filter()
                ->sortBy('name')
                ->values(),
            'assignableCountyIds' => $this->assignableCountyIds(),
        ]);
    }
}
