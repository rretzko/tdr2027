<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Models\Ensemble;
use App\Models\EnsembleGrade;
use App\Models\Event;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\VersionCloningService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Show extends Component
{
    public Event $event;

    public string $activeTab = 'versions';

    // Version creation
    public string $new_name = '';

    public string $new_short_name = '';

    public string $new_senior_class_of = '';

    // Ensemble creation/editing
    public ?int $editingEnsembleId = null;

    public string $ens_name = '';

    public string $ens_short_name = '';

    public string $ens_abbreviation = '';

    /** @var array<int, list<int>> grades per ensemble id */
    public array $ens_grades = [];

    /** @var array<int, list<int>> voice_part ids per ensemble id */
    public array $ens_voice_parts = [];

    public function mount(Event $event, VersionRoleAssignmentService $service): void
    {
        abort_unless($service->canViewEvent(Auth::user(), $event), 403);

        $this->event = $event;
        $this->new_senior_class_of = (string) ((int) date('Y') + 1);
        $this->loadEnsembleData();
    }

    // --- Versions ---

    public function openAddVersion(): void
    {
        $latest = $this->latestVersion();

        $this->new_name = '';
        $this->new_short_name = '';
        $this->new_senior_class_of = (string) ($latest ? $latest->senior_class_of + 1 : (int) date('Y') + 1);
        $this->resetValidation(['new_name', 'new_short_name', 'new_senior_class_of']);
    }

    public function createVersion(VersionRoleAssignmentService $service, VersionCloningService $cloningService): void
    {
        abort_unless($service->canManageEvent(Auth::user(), $this->event), 403);

        $validated = $this->validate([
            'new_name' => ['required', 'string', 'max:255'],
            'new_short_name' => ['nullable', 'string', 'max:100'],
            'new_senior_class_of' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $overrides = [
            'name' => $validated['new_name'],
            'short_name' => $validated['new_short_name'] ?: null,
            'senior_class_of' => (int) $validated['new_senior_class_of'],
        ];

        $latest = $this->latestVersion();

        if ($latest) {
            $version = $cloningService->cloneFrom($latest, $overrides, Auth::user());
        } else {
            $version = Version::create([
                ...$overrides,
                'event_id' => $this->event->id,
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
                'release_confidential_results' => false,
                'score_order' => ScoreOrder::Asc->value,
                'shirt_size' => false,
                'teacher_cell' => true,
                'upload_type' => UploadType::None->value,
            ]);
        }

        Flux::toast("{$version->name} has been created.");
        $this->dispatch('close-modal', name: 'add-version');
    }

    private function latestVersion(): ?Version
    {
        return $this->event->versions()->orderByDesc('senior_class_of')->first();
    }

    // --- Ensembles ---

    public function openAddEnsemble(): void
    {
        $this->editingEnsembleId = null;
        $this->ens_name = '';
        $this->ens_short_name = '';
        $this->ens_abbreviation = '';
        $this->resetValidation(['ens_name', 'ens_short_name', 'ens_abbreviation']);
    }

    public function openEditEnsemble(int $id): void
    {
        $ensemble = Ensemble::findOrFail($id);
        $this->editingEnsembleId = $id;
        $this->ens_name = $ensemble->name;
        $this->ens_short_name = $ensemble->short_name ?? '';
        $this->ens_abbreviation = $ensemble->abbreviation ?? '';
        $this->resetValidation(['ens_name', 'ens_short_name', 'ens_abbreviation']);
    }

    public function saveEnsemble(VersionRoleAssignmentService $service): void
    {
        abort_unless($service->canManageEvent(Auth::user(), $this->event), 403);

        $validated = $this->validate([
            'ens_name' => ['required', 'string', 'max:255'],
            'ens_short_name' => ['nullable', 'string', 'max:100'],
            'ens_abbreviation' => ['nullable', 'string', 'max:20'],
        ]);

        $data = [
            'event_id' => $this->event->id,
            'name' => $validated['ens_name'],
            'short_name' => $validated['ens_short_name'] ?: null,
            'abbreviation' => $validated['ens_abbreviation'] ?: null,
        ];

        if ($this->editingEnsembleId === null) {
            $ensemble = Ensemble::create($data);
            $this->ens_grades[$ensemble->id] = [];
            $this->ens_voice_parts[$ensemble->id] = [];
            Flux::toast("{$ensemble->name} has been created.");
        } else {
            $ensemble = Ensemble::findOrFail($this->editingEnsembleId);
            $ensemble->update($data);
            Flux::toast("{$ensemble->name} has been updated.");
        }

        $this->dispatch('close-modal', name: 'edit-ensemble');
        $this->editingEnsembleId = null;
    }

    public function saveEnsembleGrades(int $ensembleId, VersionRoleAssignmentService $service): void
    {
        abort_unless($service->canManageEvent(Auth::user(), $this->event), 403);

        $this->validate([
            "ens_grades.{$ensembleId}" => ['array'],
            "ens_grades.{$ensembleId}.*" => ['integer', 'min:1', 'max:12'],
        ]);

        EnsembleGrade::where('ensemble_id', $ensembleId)->delete();

        foreach ($this->ens_grades[$ensembleId] ?? [] as $grade) {
            EnsembleGrade::create(['ensemble_id' => $ensembleId, 'grade' => (int) $grade]);
        }

        Flux::toast('Grades saved.');
    }

    public function saveEnsembleVoiceParts(int $ensembleId, VersionRoleAssignmentService $service): void
    {
        abort_unless($service->canManageEvent(Auth::user(), $this->event), 403);

        $this->validate([
            "ens_voice_parts.{$ensembleId}" => ['array'],
            "ens_voice_parts.{$ensembleId}.*" => ['integer', 'exists:voice_parts,id'],
        ]);

        $ensemble = Ensemble::findOrFail($ensembleId);
        $ensemble->voiceParts()->sync($this->ens_voice_parts[$ensembleId] ?? []);

        Flux::toast('Voice parts saved.');
    }

    public function render(VersionRoleAssignmentService $service): View
    {
        $ensembles = $this->event->ensembles()->with(['grades', 'voiceParts'])->get();

        return view('livewire.events.show', [
            'versions' => $this->event->versions()->orderByDesc('senior_class_of')->get(),
            'ensembles' => $ensembles,
            'allVoiceParts' => VoicePart::ordered()->get(),
            'gradeOptions' => range(6, 12),
            'canManageEvent' => $service->canManageEvent(Auth::user(), $this->event),
        ]);
    }

    private function loadEnsembleData(): void
    {
        $ensembles = $this->event->ensembles()->with(['grades', 'voiceParts'])->get();

        foreach ($ensembles as $ensemble) {
            $this->ens_grades[$ensemble->id] = $ensemble->grades->pluck('grade')->map(fn ($g) => (int) $g)->all();
            $this->ens_voice_parts[$ensemble->id] = $ensemble->voiceParts->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
    }
}
