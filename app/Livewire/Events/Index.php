<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\EventStatus;
use App\Enums\Frequency;
use App\Models\Event;
use App\Models\Organization;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    public ?int $editingEventId = null;

    public string $edit_name = '';

    public string $edit_short_name = '';

    public string $edit_organization_id = '';

    public string $edit_status = '';

    public string $edit_frequency = '';

    public string $edit_audition_count = '1';

    public string $edit_ensemble_count = '1';

    public function add(): void
    {
        abort_unless(Auth::user()->isFounder(), 403);

        $this->editingEventId = null;
        $this->edit_name = '';
        $this->edit_short_name = '';
        $this->edit_organization_id = '';
        $this->edit_status = EventStatus::Sandbox->value;
        $this->edit_frequency = Frequency::Annual->value;
        $this->edit_audition_count = '1';
        $this->edit_ensemble_count = '1';
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        abort_unless(Auth::user()->isFounder(), 403);

        $event = Event::findOrFail($id);
        $this->editingEventId = $id;
        $this->edit_name = $event->name;
        $this->edit_short_name = $event->short_name ?? '';
        $this->edit_organization_id = (string) $event->organization_id;
        $this->edit_status = $event->getRawOriginal('status');
        $this->edit_frequency = $event->getRawOriginal('frequency');
        $this->edit_audition_count = (string) $event->audition_count;
        $this->edit_ensemble_count = (string) $event->ensemble_count;
        $this->resetValidation();
    }

    public function save(): void
    {
        abort_unless(Auth::user()->isFounder(), 403);

        $validated = $this->validate([
            'edit_name' => ['required', 'string', 'max:255'],
            'edit_short_name' => ['nullable', 'string', 'max:100'],
            'edit_organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'edit_status' => ['required', 'string', 'in:'.implode(',', array_column(EventStatus::cases(), 'value'))],
            'edit_frequency' => ['required', 'string', 'in:'.implode(',', array_column(Frequency::cases(), 'value'))],
            'edit_audition_count' => ['required', 'integer', 'min:1', 'max:10'],
            'edit_ensemble_count' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $data = [
            'name' => $validated['edit_name'],
            'short_name' => $validated['edit_short_name'] ?: null,
            'organization_id' => (int) $validated['edit_organization_id'],
            'status' => $validated['edit_status'],
            'frequency' => $validated['edit_frequency'],
            'audition_count' => (int) $validated['edit_audition_count'],
            'ensemble_count' => (int) $validated['edit_ensemble_count'],
        ];

        if ($this->editingEventId === null) {
            Event::create($data);
            $label = $validated['edit_name'];
            Flux::toast("{$label} has been created.");
        } else {
            $event = Event::findOrFail($this->editingEventId);
            $event->update($data);
            $label = $validated['edit_name'];
            Flux::toast("{$label} has been updated.");
        }

        // Flux's <flux:modal> Alpine component listens for a document-level
        // "modal-close" event carrying {name}, not "close-modal" — see
        // handleClose() in vendor/livewire/flux-pro/dist/flux.js.
        $this->dispatch('modal-close', name: 'edit-event');
    }

    /**
     * Marks the first-visit spotlight tour as seen for this user — a single
     * flag covers the Events index, mirroring Events\Show::dismissOrientation().
     * Called from the client-side tour engine (resources/views/livewire/events/index.blade.php)
     * via a hidden wire:click trigger when the tour finishes or is skipped; the
     * always-visible "Take a tour" button ignores this flag entirely.
     */
    public function dismissOrientation(): void
    {
        Auth::user()->update(['dismissed_events_index_orientation_at' => now()]);
    }

    public function render(VersionRoleAssignmentService $service): View
    {
        $user = Auth::user();

        $events = Event::with(['organization', 'versions'])
            ->when(
                ! $user->isFounder(),
                fn ($query) => $query->whereIn('id', $service->eventIdsVisibleTo($user)),
            )
            ->orderBy('name')
            ->get();

        $adjudicatableVersions = $events->mapWithKeys(
            fn (Event $event) => [$event->id => $service->adjudicatableVersionFor($user, $event)],
        );

        $hasVersionRole = $events->mapWithKeys(
            fn (Event $event) => [$event->id => $service->holdsAnyVersionScopedRoleForEvent($user, $event)],
        );

        return view('livewire.events.index', [
            'events' => $events,
            'adjudicatableVersions' => $adjudicatableVersions,
            'hasVersionRole' => $hasVersionRole,
            'organizations' => Organization::orderBy('name')->get(),
            'statuses' => EventStatus::cases(),
            'frequencies' => Frequency::cases(),
            'isFounder' => $user->isFounder(),
        ]);
    }
}
