<?php

declare(strict_types=1);

namespace App\Livewire\Schools;

use App\Enums\SchoolType;
use App\Enums\TeacherRole;
use App\Mail\SchoolEmailVerificationMail;
use App\Models\County;
use App\Models\Geostate;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Teacher;
use App\Rules\NotCommercialEmailDomain;
use App\Support\CommercialEmailDomains;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL as UrlFacade;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public string $sortColumn = 'name';

    public string $sortDirection = 'asc';

    public ?int $editingSchoolId = null;

    public string $edit_role = '';

    public bool $edit_is_replacing_teacher = false;

    public string $edit_replacing_teacher_name = '';

    public string $edit_school_email = '';

    public string $edit_name = '';

    public string $edit_type = '';

    public string $edit_city = '';

    public string $edit_zip_code = '';

    public string $edit_geostate_id = '';

    public string $edit_county_id = '';

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function deactivate(int $schoolId): void
    {
        $this->teacher()->schools()->updateExistingPivot($schoolId, ['is_active' => false]);
    }

    public function remove(int $schoolId): void
    {
        $teacher = $this->teacher();

        if (StudentTeacher::where('teacher_id', $teacher->id)->where('school_id', $schoolId)->exists()) {
            $this->addError('remove', 'This school cannot be removed while students are linked to you there.');

            return;
        }

        $teacher->schools()->detach($schoolId);
    }

    public function edit(int $schoolId): void
    {
        $school = $this->teacher()->schools()->findOrFail($schoolId);
        $pivot = $school->pivot;

        $this->editingSchoolId = $school->id;

        $this->edit_role = (string) $pivot->getRawOriginal('role');
        $this->edit_is_replacing_teacher = $pivot->replacing_teacher_name !== null;
        $this->edit_replacing_teacher_name = $pivot->replacing_teacher_name ?? '';
        $this->edit_school_email = $pivot->school_email ?? '';

        $this->edit_name = $school->name;
        $this->edit_type = (string) $school->getRawOriginal('type');
        $this->edit_city = $school->city;
        $this->edit_zip_code = $school->zip_code;
        $this->edit_geostate_id = $school->geostate_id !== null ? (string) $school->geostate_id : '';
        $this->edit_county_id = (string) $school->county_id;

        $this->resetErrorBag();
    }

    public function updatedEditGeostateId(): void
    {
        $this->edit_county_id = '';
    }

    public function schoolEmailDomainWarning(): ?string
    {
        if ($this->edit_school_email === '' || ! str_contains($this->edit_school_email, '@')) {
            return null;
        }

        if (! CommercialEmailDomains::matches($this->edit_school_email)) {
            return null;
        }

        return 'This looks like a personal email provider. School emails need to be on your school\'s own domain.';
    }

    public function saveEdit(): void
    {
        $teacher = $this->teacher();
        $school = $teacher->schools()->findOrFail($this->editingSchoolId);
        $pivot = $school->pivot;

        $schoolEmailRules = ['nullable', 'email', 'max:255'];

        if ($this->edit_school_email !== '') {
            $schoolEmailRules[] = new NotCommercialEmailDomain;
        }

        $this->validate([
            'edit_role' => ['required', Rule::in([TeacherRole::Primary->value, TeacherRole::Coteacher->value])],
            'edit_replacing_teacher_name' => [$this->edit_is_replacing_teacher ? 'required' : 'nullable', 'string', 'max:255'],
            'edit_school_email' => $schoolEmailRules,
            'edit_name' => [
                'required', 'string', 'max:255',
                Rule::unique('schools', 'name')->where('zip_code', $this->edit_zip_code)->ignore($school->id),
            ],
            'edit_type' => ['required', Rule::in([SchoolType::School->value, SchoolType::Studio->value])],
            'edit_city' => ['required', 'string', 'max:255'],
            'edit_zip_code' => ['required', 'string', 'max:5'],
            'edit_geostate_id' => ['nullable', 'integer', Rule::exists(Geostate::class, 'id')],
            'edit_county_id' => ['required', 'integer', Rule::exists(County::class, 'id')],
        ]);

        $newSchoolEmail = $this->edit_school_email !== '' ? $this->edit_school_email : null;
        // Normalized in case school_email was left as '' rather than NULL by a direct
        // database edit — otherwise that's indistinguishable from a real change below.
        $previousSchoolEmail = $pivot->school_email !== '' ? $pivot->school_email : null;

        $teacher->schools()->updateExistingPivot($school->id, [
            'role' => $this->edit_role,
            'replacing_teacher_name' => $this->edit_is_replacing_teacher ? $this->edit_replacing_teacher_name : null,
            'school_email' => $newSchoolEmail,
            ...$this->schoolEmailVerificationFields($previousSchoolEmail, $newSchoolEmail, $teacher),
        ]);

        $school->update([
            'name' => $this->edit_name,
            'type' => $this->edit_type,
            'city' => $this->edit_city,
            'zip_code' => $this->edit_zip_code,
            'geostate_id' => $this->edit_geostate_id !== '' ? (int) $this->edit_geostate_id : null,
            'county_id' => (int) $this->edit_county_id,
        ]);

        if ($newSchoolEmail !== null && $newSchoolEmail !== $previousSchoolEmail) {
            $this->sendVerificationEmailIfNeeded($school->id, $teacher);
        }

        $this->editingSchoolId = null;
        $this->modal('edit-school')->close();

        Flux::toast(text: "{$school->name} updated successfully.", variant: 'success');
    }

    /**
     * When school_email changes, its verification status doesn't carry over to the
     * new address: it's auto-verified without sending mail when it matches the
     * teacher's own already-verified account email, otherwise it's reset to pending
     * and a verification link is sent (see sendVerificationEmailIfNeeded()). When
     * school_email is unchanged, verified_at is left out entirely so it's untouched.
     *
     * @return array{verified_at?: Carbon|null}
     */
    private function schoolEmailVerificationFields(?string $previousSchoolEmail, ?string $newSchoolEmail, Teacher $teacher): array
    {
        if ($newSchoolEmail === $previousSchoolEmail) {
            return [];
        }

        if ($newSchoolEmail !== null
            && $newSchoolEmail === $teacher->user->email
            && $teacher->user->hasVerifiedEmail()
        ) {
            return ['verified_at' => now()];
        }

        return ['verified_at' => null];
    }

    private function sendVerificationEmailIfNeeded(int $schoolId, Teacher $teacher): void
    {
        // Re-fetched because updateExistingPivot() ran a raw query update — the
        // in-memory $pivot from saveEdit() still holds the pre-update verified_at.
        $pivot = SchoolTeacher::where('school_id', $schoolId)->where('teacher_id', $teacher->id)->first();

        if ($pivot === null || $pivot->verified_at !== null || $pivot->school_email === null) {
            return;
        }

        $verificationUrl = UrlFacade::temporarySignedRoute(
            'school-email.verify',
            now()->addDays(3),
            ['schoolTeacher' => $pivot->id, 'email' => $pivot->school_email],
        );

        Mail::to($pivot->school_email)->send(new SchoolEmailVerificationMail($pivot, $verificationUrl));
    }

    public function render(): View
    {
        return view('livewire.schools.index', [
            'schools' => $this->schools(),
            'geostates' => Geostate::orderBy('name')->get(),
            'editCounties' => $this->edit_geostate_id !== ''
                ? County::where('geostate_id', $this->edit_geostate_id)->orderBy('name')->get()
                : collect(),
        ]);
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }

    /**
     * @return LengthAwarePaginator<int, School>
     */
    private function schools(): LengthAwarePaginator
    {
        return $this->teacher()->schools()
            ->with(['county', 'geostate'])
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->orderBy($this->sortColumn, $this->sortDirection)
            ->paginate(15);
    }
}
