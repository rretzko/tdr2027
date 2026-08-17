<?php

declare(strict_types=1);

namespace App\Livewire\Sfdi;

use App\Enums\EmergencyContactRelationship;
use App\Models\EmergencyContact;
use App\Models\Student;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Student self-service Emergency Contact(s) CRUD — the same
 * `emergency_contacts` rows CandidateDetail's teacher-side modal already
 * edits (per studentfolder-module.md §4.3), just reachable directly from
 * the student's own profile rather than through a specific Candidate.
 */
#[Layout('components.layouts.app')]
class EmergencyContacts extends Component
{
    public ?int $editingEmergencyContactId = null;

    public string $ec_name = '';

    public string $ec_relationship = '';

    public string $ec_email = '';

    public string $ec_cell_phone = '';

    public string $ec_work_phone = '';

    public string $ec_home_phone = '';

    public function mount(): void
    {
        abort_if($this->student() === null, 403);
    }

    public function addEmergencyContact(): void
    {
        $this->editingEmergencyContactId = null;
        $this->resetContactFields();
        $this->modal('emergency-contact')->show();
    }

    public function editEmergencyContact(int $emergencyContactId): void
    {
        $ec = $this->findOwnedContact($emergencyContactId);

        $this->editingEmergencyContactId = $ec->id;
        $this->ec_name = $ec->name;
        $this->ec_relationship = (string) $ec->getRawOriginal('relationship');
        $this->ec_email = $ec->email ?? '';
        $this->ec_cell_phone = $ec->cell_phone ?? '';
        $this->ec_work_phone = $ec->work_phone ?? '';
        $this->ec_home_phone = $ec->home_phone ?? '';
        $this->resetErrorBag();
        $this->modal('emergency-contact')->show();
    }

    public function save(): void
    {
        $this->validate([
            'ec_name' => ['required', 'string', 'max:255'],
            'ec_relationship' => ['required', 'string', 'in:'.implode(',', array_column(EmergencyContactRelationship::cases(), 'value'))],
            'ec_email' => ['nullable', 'email', 'max:255'],
            'ec_cell_phone' => ['nullable', 'string', 'max:30'],
            'ec_work_phone' => ['nullable', 'string', 'max:30'],
            'ec_home_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $attributes = [
            'name' => $this->ec_name,
            'relationship' => $this->ec_relationship,
            'email' => $this->ec_email !== '' ? $this->ec_email : null,
            'cell_phone' => $this->ec_cell_phone !== '' ? $this->ec_cell_phone : null,
            'work_phone' => $this->ec_work_phone !== '' ? $this->ec_work_phone : null,
            'home_phone' => $this->ec_home_phone !== '' ? $this->ec_home_phone : null,
        ];

        if ($this->editingEmergencyContactId !== null) {
            $this->findOwnedContact($this->editingEmergencyContactId)->update($attributes);
        } else {
            EmergencyContact::create(['student_id' => $this->student()->id, ...$attributes]);
        }

        $this->modal('emergency-contact')->close();
        $this->editingEmergencyContactId = null;
        $this->resetContactFields();

        Flux::toast('Emergency contact saved.');
    }

    public function remove(int $emergencyContactId): void
    {
        $this->findOwnedContact($emergencyContactId)->delete();

        Flux::toast('Emergency contact removed.');
    }

    public function render(): View
    {
        return view('livewire.sfdi.emergency-contacts', [
            'contacts' => $this->student()->emergencyContacts()->orderBy('name')->get(),
            'relationships' => EmergencyContactRelationship::cases(),
        ]);
    }

    private function findOwnedContact(int $emergencyContactId): EmergencyContact
    {
        $ec = EmergencyContact::where('id', $emergencyContactId)
            ->where('student_id', $this->student()->id)
            ->first();

        abort_if($ec === null, 404);

        return $ec;
    }

    private function resetContactFields(): void
    {
        $this->ec_name = '';
        $this->ec_relationship = '';
        $this->ec_email = '';
        $this->ec_cell_phone = '';
        $this->ec_work_phone = '';
        $this->ec_home_phone = '';
        $this->resetErrorBag();
    }

    private function student(): ?Student
    {
        return Auth::user()->student;
    }
}
