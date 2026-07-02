<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\HasCandidateChecklist;
use App\Enums\EmergencyContactRelationship;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\Teacher;
use App\Models\Version;
use App\Services\CandidateService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class CandidateDetail extends Component
{
    use HasCandidateChecklist;

    public Version $version;

    public Candidate $candidate;

    // Program name
    public string $program_name = '';

    // Emergency contact form
    public string $ec_name = '';

    public string $ec_relationship = '';

    public string $ec_cell_phone = '';

    public string $ec_home_phone = '';

    public string $ec_email = '';

    public function mount(Version $version, Candidate $candidate): void
    {
        abort_if($candidate->version_id !== $version->id, 404);
        abort_if($candidate->teacher_id !== $this->teacher()->id, 403);

        $this->version = $version;
        $this->candidate = $candidate->load([
            'student.user',
            'student.homeAddress',
            'student.emergencyContacts',
            'voicePart',
        ]);

        $this->program_name = $candidate->program_name;
    }

    public function saveProgramName(CandidateService $candidates): void
    {
        $this->validate(['program_name' => ['required', 'string', 'max:255']]);

        $this->candidate->update(['program_name' => $this->program_name]);
        $candidates->recalculateStatus(
            $this->candidate->refresh(),
            $this->checklistDefs($this->version),
        );

        Flux::toast('Program name saved.');
    }

    public function saveEmergencyContact(CandidateService $candidates): void
    {
        $this->validate([
            'ec_name' => ['required', 'string', 'max:255'],
            'ec_relationship' => ['required', 'string', 'in:'.implode(',', array_column(EmergencyContactRelationship::cases(), 'value'))],
            'ec_cell_phone' => ['nullable', 'string', 'max:30'],
            'ec_home_phone' => ['nullable', 'string', 'max:30'],
            'ec_email' => ['nullable', 'email', 'max:255'],
        ]);

        $ec = EmergencyContact::create([
            'student_id' => $this->candidate->student_id,
            'name' => $this->ec_name,
            'relationship' => $this->ec_relationship,
            'cell_phone' => $this->ec_cell_phone ?: null,
            'home_phone' => $this->ec_home_phone ?: null,
            'email' => $this->ec_email ?: null,
        ]);

        if ($this->candidate->emergency_contact_id === null) {
            $this->candidate->update(['emergency_contact_id' => $ec->id]);
        }

        $this->ec_name = '';
        $this->ec_relationship = '';
        $this->ec_cell_phone = '';
        $this->ec_home_phone = '';
        $this->ec_email = '';
        $this->resetValidation(['ec_name', 'ec_relationship', 'ec_cell_phone', 'ec_home_phone', 'ec_email']);

        $candidates->recalculateStatus(
            $this->candidate->load('student.emergencyContacts')->refresh(),
            $this->checklistDefs($this->version),
        );

        Flux::toast("{$ec->name} added as emergency contact.");
    }

    public function refreshStatus(CandidateService $candidates): void
    {
        $candidates->recalculateStatus(
            $this->candidate->load(['student.homeAddress', 'student.emergencyContacts'])->refresh(),
            $this->checklistDefs($this->version),
        );

        Flux::toast('Status refreshed.');
    }

    public function render(): View
    {
        $this->candidate->load([
            'student.user',
            'student.homeAddress',
            'student.emergencyContacts',
            'voicePart',
        ]);

        $checklistDefs = $this->checklistDefs($this->version);

        return view('livewire.registrations.candidate-detail', [
            'checklistDefs' => $checklistDefs,
            'relationships' => EmergencyContactRelationship::cases(),
        ]);
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
