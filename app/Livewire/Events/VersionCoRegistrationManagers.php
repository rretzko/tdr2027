<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Models\CoRegistrationManagerCounty;
use App\Models\Geostate;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionMailToAddress;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    // Mail-to address (§5.12)
    public string $mailto_recipient_name = '';

    public string $mailto_organization_line = '';

    public string $mailto_address_line1 = '';

    public string $mailto_address_line2 = '';

    public string $mailto_city = '';

    public ?int $mailto_geostate_id = null;

    public string $mailto_zip = '';

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
        $this->resetMailToAddress();
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
        $this->hydrateMailToAddress($userId);
        $this->resetErrorBag();
    }

    /**
     * Pre-fills the mail-to address fields from any existing
     * version_mail_to_addresses row for $userId, defaulting recipient name
     * to the user's own name (everything else blank) if none exists yet —
     * same default rule as VersionEdit::editMailToAddress(). See §5.12.
     */
    private function hydrateMailToAddress(int $userId): void
    {
        $targetUser = User::findOrFail($userId);
        $existing = VersionMailToAddress::where('version_id', $this->version->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing !== null) {
            $this->mailto_recipient_name = $existing->recipient_name;
            $this->mailto_organization_line = $existing->organization_line ?? '';
            $this->mailto_address_line1 = $existing->address_line1;
            $this->mailto_address_line2 = $existing->address_line2 ?? '';
            $this->mailto_city = $existing->city;
            $this->mailto_geostate_id = $existing->geostate_id;
            $this->mailto_zip = $existing->zip;
        } else {
            $this->mailto_recipient_name = $targetUser->name;
            $this->mailto_organization_line = '';
            $this->mailto_address_line1 = '';
            $this->mailto_address_line2 = '';
            $this->mailto_city = '';
            $this->mailto_geostate_id = null;
            $this->mailto_zip = '';
        }
    }

    private function resetMailToAddress(): void
    {
        $this->mailto_recipient_name = '';
        $this->mailto_organization_line = '';
        $this->mailto_address_line1 = '';
        $this->mailto_address_line2 = '';
        $this->mailto_city = '';
        $this->mailto_geostate_id = null;
        $this->mailto_zip = '';
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
        $this->hydrateMailToAddress($userId);
    }

    public function clearSelection(): void
    {
        $this->selectedUserId = null;
        $this->search = '';
    }

    public function save(VersionRoleAssignmentService $roles): void
    {
        $assignableCountyIds = $this->assignableCountyIds();

        // The mail-to address is optional at save time — filling it in is
        // bundled into this same form for convenience (§5.12), but an Event
        // Manager/Registration Manager may still just be assigning the role
        // itself and come back to the address later, so it's only validated
        // (and only required in full) when address_line1 is actually filled
        // in. See mailToAddressProvided() below.
        $addressProvided = trim($this->mailto_address_line1) !== '';

        $validated = $this->validate([
            'selectedUserId' => ['required', 'integer', 'exists:users,id'],
            'countyIds' => ['array'],
            'countyIds.*' => ['integer', Rule::in($assignableCountyIds)],
            'mailto_recipient_name' => [$addressProvided ? 'required' : 'nullable', 'string', 'max:255'],
            'mailto_organization_line' => ['nullable', 'string', 'max:255'],
            'mailto_address_line1' => ['nullable', 'string', 'max:255'],
            'mailto_address_line2' => ['nullable', 'string', 'max:255'],
            'mailto_city' => [$addressProvided ? 'required' : 'nullable', 'string', 'max:255'],
            'mailto_geostate_id' => [$addressProvided ? 'required' : 'nullable', 'integer', 'exists:geostates,id'],
            'mailto_zip' => [$addressProvided ? 'required' : 'nullable', 'string', 'max:20'],
        ]);

        $targetUser = User::findOrFail($validated['selectedUserId']);

        DB::transaction(function () use ($roles, $targetUser, $validated, $addressProvided): void {
            $roles->assignCoRegistrationManager(Auth::user(), $this->version, $targetUser, $validated['countyIds']);

            if ($addressProvided) {
                VersionMailToAddress::updateOrCreate(
                    ['version_id' => $this->version->id, 'user_id' => $targetUser->id],
                    [
                        'recipient_name' => $validated['mailto_recipient_name'],
                        'organization_line' => ($validated['mailto_organization_line'] ?? '') !== '' ? $validated['mailto_organization_line'] : null,
                        'address_line1' => $validated['mailto_address_line1'],
                        'address_line2' => ($validated['mailto_address_line2'] ?? '') !== '' ? $validated['mailto_address_line2'] : null,
                        'city' => $validated['mailto_city'],
                        'geostate_id' => $validated['mailto_geostate_id'],
                        'zip' => $validated['mailto_zip'],
                    ],
                );
            } else {
                // Address line 1 was cleared out (or never filled in) —
                // treat that as "no address configured", including removing
                // one that existed before this save, rather than leaving a
                // stale row behind.
                VersionMailToAddress::where('version_id', $this->version->id)
                    ->where('user_id', $targetUser->id)
                    ->delete();
            }
        });

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
            'geostates' => Geostate::orderBy('name')->get(),
        ]);
    }
}
