<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Models\Event;
use App\Models\Version;
use Flux\Flux;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Show extends Component
{
    public Event $event;

    public bool $addingVersion = false;

    public string $new_name = '';

    public string $new_short_name = '';

    public string $new_senior_class_of = '';

    public function mount(Event $event): void
    {
        $this->event = $event;
        $this->new_senior_class_of = (string) ((int) date('Y') + 1);
    }

    public function openAddVersion(): void
    {
        $this->addingVersion = true;
        $this->new_name = '';
        $this->new_short_name = '';
        $this->new_senior_class_of = (string) ((int) date('Y') + 1);
        $this->resetValidation();
    }

    public function createVersion(): void
    {
        $validated = $this->validate([
            'new_name' => ['required', 'string', 'max:255'],
            'new_short_name' => ['nullable', 'string', 'max:100'],
            'new_senior_class_of' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $version = Version::create([
            'event_id' => $this->event->id,
            'name' => $validated['new_name'],
            'short_name' => $validated['new_short_name'] ?: null,
            'senior_class_of' => (int) $validated['new_senior_class_of'],
            'status' => EventStatus::Sandbox->value,
            'application_type' => ApplicationType::Pdf->value,
            'audition_timeslot' => 20,
            'audition_type' => AuditionType::Remote->value,
            'birthday' => false,
            'emergency_contact_name' => true,
            'emergency_contact_cell' => true,
            'emergency_contact_email' => false,
            'height' => false,
            'home_address' => false,
            'judge_count' => 1,
            'pitch_file_visibility' => PitchFileVisibility::Both->value,
            'release_confidential_results' => false,
            'score_order' => ScoreOrder::Asc->value,
            'shirt_size' => false,
            'teacher_cell' => true,
            'upload_type' => UploadType::None->value,
        ]);

        Flux::toast("{$version->name} has been created.");
        $this->dispatch('close-modal', name: 'add-version');
        $this->addingVersion = false;
        $this->event->refresh();
    }

    public function render(): View
    {
        return view('livewire.events.show', [
            'versions' => $this->event->versions()->orderByDesc('senior_class_of')->get(),
        ]);
    }
}
