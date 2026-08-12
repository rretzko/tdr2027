<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\GuardsAcceptedObligations;
use App\Concerns\HasCandidateChecklist;
use App\Enums\ApplicationType;
use App\Enums\EmergencyContactRelationship;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Services\CandidateService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class CandidateDetail extends Component
{
    use GuardsAcceptedObligations;
    use HasCandidateChecklist;

    public Version $version;

    public Candidate $candidate;

    // Program name
    public string $program_name = '';

    // Student form
    public string $edit_first_name = '';

    public string $edit_last_name = '';

    public string $edit_voice_part_id = '';

    public string $edit_birthday = '';

    public string $edit_height = '';

    // Home address form
    public string $edit_home_address1 = '';

    public string $edit_home_address2 = '';

    public string $edit_home_city = '';

    public string $edit_home_geo_state = '';

    public string $edit_home_zip_code = '';

    // Emergency contact form
    public ?int $editingEmergencyContactId = null;

    public string $ec_name = '';

    public string $ec_relationship = '';

    public string $ec_cell_phone = '';

    public string $ec_home_phone = '';

    public string $ec_email = '';

    public function mount(Version $version, Candidate $candidate): void
    {
        abort_if($candidate->version_id !== $version->id, 404);
        abort_if($candidate->teacher_id !== $this->teacher()->id, 403);

        $invitation = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $this->teacher()->id)
            ->first();

        if ($this->redirectUnlessObligationsAccepted($version, $invitation)) {
            return;
        }

        $this->version = $version;
        $this->candidate = $candidate->load([
            'student.user',
            'student.homeAddress',
            'student.emergencyContacts',
            'voicePart',
        ]);

        $this->program_name = $candidate->program_name;
    }

    public function editProgramName(): void
    {
        $this->program_name = $this->candidate->program_name;
        $this->resetErrorBag('program_name');
        $this->modal('edit-program-name')->show();
    }

    public function saveProgramName(CandidateService $candidates): void
    {
        $this->validate(['program_name' => ['required', 'string', 'max:255']]);

        $this->candidate->update(['program_name' => $this->program_name]);
        $candidates->recalculateStatus(
            $this->candidate->refresh(),
            $this->checklistDefs($this->version),
        );

        $this->modal('edit-program-name')->close();

        Flux::toast('Program name saved.');
    }

    public function editStudent(): void
    {
        $student = $this->candidate->student;
        $this->edit_first_name = $student->user->first_name;
        $this->edit_last_name = $student->user->last_name;
        // Sourced from the Candidate, not the Student — this is this
        // registration's voice part, which can differ from whatever the
        // Student's general profile has on file.
        $this->edit_voice_part_id = (string) $this->candidate->voice_part_id;
        $this->edit_birthday = (string) $student->getRawOriginal('birthday');
        $this->edit_height = $student->height !== null ? (string) $student->height : '';
        $this->resetErrorBag(['edit_first_name', 'edit_last_name', 'edit_voice_part_id', 'edit_birthday', 'edit_height']);
        $this->modal('edit-student')->show();
    }

    public function saveStudent(CandidateService $candidates): void
    {
        $this->validate([
            'edit_first_name' => ['required', 'string', 'max:255', "regex:/^[\pL\s'-]+$/u"],
            'edit_last_name' => ['required', 'string', 'max:255', "regex:/^[\pL\s'-]+$/u"],
            'edit_voice_part_id' => ['required', 'integer', Rule::in($this->version->availableVoiceParts()->pluck('id')->all())],
            'edit_birthday' => [
                'nullable', 'date',
                'before_or_equal:'.now()->subYears(9)->format('Y-m-d'),
                'after:'.now()->subYears(20)->format('Y-m-d'),
            ],
            'edit_height' => ['nullable', 'integer', 'min:30', 'max:84'],
        ]);

        $this->candidate->student->user->update([
            'first_name' => $this->edit_first_name,
            'last_name' => $this->edit_last_name,
        ]);

        $this->candidate->student->update([
            'birthday' => $this->edit_birthday !== '' ? $this->edit_birthday : null,
            'height' => $this->edit_height !== '' ? (int) $this->edit_height : null,
        ]);

        $this->candidate->update(['voice_part_id' => (int) $this->edit_voice_part_id]);

        $candidates->recalculateStatus(
            $this->candidate->refresh(),
            $this->checklistDefs($this->version),
        );

        $this->modal('edit-student')->close();

        Flux::toast('Student info saved.');
    }

    public function editHomeAddress(): void
    {
        $homeAddress = $this->candidate->student->homeAddress;
        $this->edit_home_address1 = $homeAddress !== null ? $homeAddress->address1 : '';
        $this->edit_home_address2 = $homeAddress !== null ? ($homeAddress->address2 ?? '') : '';
        $this->edit_home_city = $homeAddress !== null ? $homeAddress->city : '';
        $this->edit_home_geo_state = $homeAddress !== null ? $homeAddress->geo_state : '';
        $this->edit_home_zip_code = $homeAddress !== null ? $homeAddress->zip_code : '';
        $this->resetErrorBag(['edit_home_address1', 'edit_home_address2', 'edit_home_city', 'edit_home_geo_state', 'edit_home_zip_code']);
        $this->modal('edit-home-address')->show();
    }

    public function saveHomeAddress(CandidateService $candidates): void
    {
        $this->validate([
            'edit_home_address1' => ['required', 'string', 'max:255'],
            'edit_home_address2' => ['nullable', 'string', 'max:255'],
            'edit_home_city' => ['required', 'string', 'max:255'],
            'edit_home_geo_state' => ['required', 'string', 'max:2'],
            'edit_home_zip_code' => ['required', 'string', 'max:10'],
        ]);

        $this->candidate->student->homeAddress()->updateOrCreate([], [
            'address1' => $this->edit_home_address1,
            'address2' => $this->edit_home_address2 !== '' ? $this->edit_home_address2 : null,
            'city' => $this->edit_home_city,
            'geo_state' => $this->edit_home_geo_state,
            'zip_code' => $this->edit_home_zip_code,
        ]);

        $candidates->recalculateStatus(
            $this->candidate->load('student.homeAddress')->refresh(),
            $this->checklistDefs($this->version),
        );

        $this->modal('edit-home-address')->close();

        Flux::toast('Home address saved.');
    }

    public function addEmergencyContact(): void
    {
        $this->editingEmergencyContactId = null;
        $this->ec_name = '';
        $this->ec_relationship = '';
        $this->ec_cell_phone = '';
        $this->ec_home_phone = '';
        $this->ec_email = '';
        $this->resetErrorBag(['ec_name', 'ec_relationship', 'ec_cell_phone', 'ec_home_phone', 'ec_email']);
        $this->modal('add-emergency-contact')->show();
    }

    public function editEmergencyContact(int $emergencyContactId): void
    {
        $ec = EmergencyContact::where('id', $emergencyContactId)
            ->where('student_id', $this->candidate->student_id)
            ->first();

        abort_if($ec === null, 404);

        $this->editingEmergencyContactId = $ec->id;
        $this->ec_name = $ec->name;
        $this->ec_relationship = (string) $ec->getRawOriginal('relationship');
        $this->ec_cell_phone = $ec->cell_phone ?? '';
        $this->ec_home_phone = $ec->home_phone ?? '';
        $this->ec_email = $ec->email ?? '';
        $this->resetErrorBag(['ec_name', 'ec_relationship', 'ec_cell_phone', 'ec_home_phone', 'ec_email']);
        $this->modal('add-emergency-contact')->show();
    }

    public function saveEmergencyContact(CandidateService $candidates): void
    {
        $this->validate([
            'ec_name' => ['required', 'string', 'max:255'],
            'ec_relationship' => ['required', 'string', 'in:'.implode(',', array_column(EmergencyContactRelationship::cases(), 'value'))],
            // Cell/email are only mandatory when this Version's config
            // requires them (versions.emergency_contact_cell/email) — the
            // rest of the app (HasCandidateChecklist) is conditional on the
            // same two flags, and emergency_contacts.cell_phone/email are
            // nullable columns precisely because most Versions don't require
            // both.
            'ec_cell_phone' => [(bool) $this->version->emergency_contact_cell ? 'required' : 'nullable', 'string', 'max:30'],
            'ec_home_phone' => ['nullable', 'string', 'max:30'],
            'ec_email' => [(bool) $this->version->emergency_contact_email ? 'required' : 'nullable', 'email', 'max:255'],
        ]);

        $attributes = [
            'name' => $this->ec_name,
            'relationship' => $this->ec_relationship,
            'cell_phone' => $this->ec_cell_phone ?: null,
            'home_phone' => $this->ec_home_phone ?: null,
            'email' => $this->ec_email ?: null,
        ];

        if ($this->editingEmergencyContactId !== null) {
            $ec = EmergencyContact::where('id', $this->editingEmergencyContactId)
                ->where('student_id', $this->candidate->student_id)
                ->first();

            abort_if($ec === null, 404);

            $ec->update($attributes);
        } else {
            $ec = EmergencyContact::create(['student_id' => $this->candidate->student_id, ...$attributes]);

            if ($this->candidate->emergency_contact_id === null) {
                $this->candidate->update(['emergency_contact_id' => $ec->id]);
            }
        }

        $this->editingEmergencyContactId = null;
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

        $this->modal('add-emergency-contact')->close();

        Flux::toast("{$ec->name} saved as emergency contact.");
    }

    public function toggleApplicationCertified(CandidateService $candidates): void
    {
        if ($this->version->getRawOriginal('application_type') !== ApplicationType::Pdf->value) {
            return;
        }

        if ($this->candidate->application_certified_at === null) {
            $this->candidate->update([
                'application_certified_at' => now(),
                'application_certified_by_user_id' => Auth::id(),
            ]);
        } else {
            $this->candidate->update([
                'application_certified_at' => null,
                'application_certified_by_user_id' => null,
            ]);
        }

        $candidates->recalculateStatus($this->candidate->refresh(), $this->checklistDefs($this->version));

        Flux::toast('Application certification updated.');
    }

    public function toggleApplicationCandidateSigned(CandidateService $candidates): void
    {
        if ($this->version->getRawOriginal('application_type') !== ApplicationType::EApplication->value) {
            return;
        }

        $this->candidate->update([
            'application_candidate_signed_at' => $this->candidate->application_candidate_signed_at === null ? now() : null,
        ]);

        $candidates->recalculateStatus($this->candidate->refresh(), $this->checklistDefs($this->version));

        Flux::toast('Candidate signature status updated.');
    }

    public function toggleApplicationParentSigned(CandidateService $candidates): void
    {
        if ($this->version->getRawOriginal('application_type') !== ApplicationType::EApplication->value) {
            return;
        }

        $this->candidate->update([
            'application_parent_signed_at' => $this->candidate->application_parent_signed_at === null ? now() : null,
        ]);

        $candidates->recalculateStatus($this->candidate->refresh(), $this->checklistDefs($this->version));

        Flux::toast('Parent signature status updated.');
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
            'voiceParts' => $this->version->availableVoiceParts(),
        ]);
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
