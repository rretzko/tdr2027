<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\Frequency;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Self-service event creation: any authenticated teacher can start a new
 * event without already holding a version-scoped role on it. The new Event
 * and its first Version are created in Sandbox status, and the creator is
 * immediately granted "Event Manager" on that Version so they can continue
 * configuring it — see VersionRoleAssignmentService::bootstrapEventManager().
 */
#[Layout('components.layouts.app')]
class CreateEvent extends Component
{
    public string $name = '';

    public string $short_name = '';

    public string $organization_id = '';

    public string $frequency = '';

    // Event Managers section — the creator is always bootstrapped as Event
    // Manager separately (see create() below); these are additional teachers
    // picked up front rather than invited afterward via the Roles tab.
    public string $event_manager_search = '';

    /**
     * @var list<int>
     */
    public array $event_manager_ids = [];

    public function mount(): void
    {
        $this->frequency = Frequency::Annual->value;
    }

    public function addEventManager(int $userId): void
    {
        if ($userId === Auth::id() || in_array($userId, $this->event_manager_ids, true)) {
            $this->event_manager_search = '';

            return;
        }

        $exists = User::query()->whereHas('teacher')->whereKey($userId)->exists();

        if (! $exists) {
            return;
        }

        $this->event_manager_ids[] = $userId;
        $this->event_manager_search = '';
    }

    public function removeEventManager(int $userId): void
    {
        $this->event_manager_ids = array_values(array_diff($this->event_manager_ids, [$userId]));
    }

    /**
     * @return Collection<int, User>
     */
    public function eventManagerSearchResults(): Collection
    {
        $term = trim($this->event_manager_search);

        if ($term === '') {
            return collect();
        }

        return User::query()
            ->whereHas('teacher')
            ->where('id', '!=', Auth::id())
            ->whereNotIn('id', $this->event_manager_ids)
            ->where('name', 'like', "%{$term}%")
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    public function create(VersionRoleAssignmentService $service): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'frequency' => ['required', 'string', 'in:'.implode(',', array_column(Frequency::cases(), 'value'))],
        ]);

        [$event, $version] = DB::transaction(function () use ($validated, $service) {
            $event = Event::create([
                'organization_id' => (int) $validated['organization_id'],
                'name' => $validated['name'],
                'short_name' => $validated['short_name'] ?: null,
                'status' => EventStatus::Sandbox->value,
                'frequency' => $validated['frequency'],
                'audition_count' => 1,
                'ensemble_count' => 1,
            ]);

            $version = Version::create([
                'event_id' => $event->id,
                'name' => $validated['name'],
                'short_name' => $validated['short_name'] ?: null,
                'senior_class_of' => (int) date('Y') + 1,
                'status' => EventStatus::Sandbox->value,
                'application_type' => ApplicationType::Pdf->value,
                'audition_timeslot' => 0,
                'audition_type' => AuditionType::Remote->value,
                'birthday' => false,
                'emergency_contact_name' => true,
                'emergency_contact_cell' => true,
                'emergency_contact_email' => false,
                'height' => false,
                'home_address' => false,
                'judge_count' => 1,
                'pitch_file_visibility' => PitchFileVisibility::Both->value,
                'score_order' => ScoreOrder::Asc->value,
                'shirt_size' => false,
                'teacher_cell' => true,
                'upload_type' => UploadType::None->value,
            ]);

            $service->bootstrapEventManager(Auth::user(), $version);

            foreach (User::query()->whereKey($this->event_manager_ids)->get() as $additionalManager) {
                $service->assignRole(Auth::user(), $version, $additionalManager, 'Event Manager');
            }

            return [$event, $version];
        });

        Flux::toast("{$event->name} has been created.");

        $this->redirectRoute('events.show', ['event' => $event->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.events.create-event', [
            'organizations' => Organization::orderBy('name')->get(),
            'frequencies' => Frequency::cases(),
            'selectedEventManagers' => User::query()->whereKey($this->event_manager_ids)->orderBy('name')->get(),
            'eventManagerSearchResults' => $this->eventManagerSearchResults(),
        ]);
    }
}
