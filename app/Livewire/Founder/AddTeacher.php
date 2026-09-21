<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Enums\SchoolType;
use App\Mail\SchoolEmailVerificationMail;
use App\Models\County;
use App\Models\Geostate;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pronoun;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Support\SchoolMatcher;
use Flux\Flux;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL as UrlFacade;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class AddTeacher extends Component
{
    // Teacher/user details
    public string $honorific = '';

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $suffix_name = '';

    public string $pronoun_id = '';

    public string $email = '';

    public string $cell_phone = '';

    // School search/select — mirrors TeacherOnboardingWizard step 1.
    public string $geostate_id = '';

    public string $zip_code = '';

    public string $school_search = '';

    public bool $creatingNewSchool = false;

    public string $new_school_name = '';

    public string $new_school_type = 'school';

    public string $new_school_city = '';

    public string $new_school_zip_code = '';

    public string $new_school_county_id = '';

    public ?int $selectedSchoolId = null;

    public ?string $selectedSchoolName = null;

    public string $school_email = '';

    // Set once the teacher has been created, to drive the verification panel.
    public ?int $createdUserId = null;

    public ?int $createdSchoolTeacherId = null;

    public function mount(): void
    {
        $newJersey = Geostate::where('name', 'New Jersey')->first();
        $this->geostate_id = $newJersey !== null ? (string) $newJersey->id : '';
    }

    public function selectSchool(int $schoolId): void
    {
        $school = School::findOrFail($schoolId);

        $this->selectedSchoolId = $school->id;
        $this->selectedSchoolName = $school->name;
        $this->creatingNewSchool = false;
    }

    public function clearSelectedSchool(): void
    {
        $this->selectedSchoolId = null;
        $this->selectedSchoolName = null;
    }

    public function createSchool(): void
    {
        $this->validate([
            'geostate_id' => ['required', 'integer', Rule::exists(Geostate::class, 'id')],
            'new_school_name' => ['required', 'string', 'max:255'],
            'new_school_type' => ['required', Rule::in([SchoolType::School->value, SchoolType::Studio->value])],
            'new_school_city' => ['required', 'string', 'max:255'],
            'new_school_zip_code' => ['required', 'string', 'max:5'],
            'new_school_county_id' => ['required', 'integer', Rule::exists(County::class, 'id')],
        ]);

        $school = School::create([
            'name' => $this->new_school_name,
            'type' => $this->new_school_type,
            'city' => $this->new_school_city,
            'zip_code' => $this->new_school_zip_code,
            'geostate_id' => (int) $this->geostate_id,
            'county_id' => (int) $this->new_school_county_id,
            'school_year' => 'US',
        ]);

        $this->selectedSchoolId = $school->id;
        $this->selectedSchoolName = $school->name;
        $this->creatingNewSchool = false;
    }

    public function createTeacher(): void
    {
        $this->validate([
            'honorific' => ['nullable', 'string', 'max:50'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix_name' => ['nullable', 'string', 'max:50'],
            'pronoun_id' => ['required', 'integer', Rule::exists(Pronoun::class, 'id')],
            'email' => ['required', 'string', 'email:rfc,filter', 'max:255', Rule::unique(User::class)],
            'cell_phone' => ['nullable', 'string', 'min:10', 'max:20', Rule::unique('users', 'cell_phone')],
            'school_email' => ['nullable', 'string', 'email:rfc,filter', 'max:255'],
        ]);

        if ($this->selectedSchoolId === null) {
            $this->addError('selectedSchoolId', 'Select or create a school for this teacher.');

            return;
        }

        $user = User::create([
            'honorific' => $this->honorific ?: null,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name ?: null,
            'last_name' => $this->last_name,
            'suffix_name' => $this->suffix_name ?: null,
            'pronoun_id' => (int) $this->pronoun_id,
            'email' => $this->email,
            'password' => null,
        ]);

        if (filled($this->cell_phone)) {
            $user->update(['cell_phone' => preg_replace('/\D/', '', $this->cell_phone)]);
        }

        $teacher = Teacher::create(['user_id' => $user->id]);

        $user->assignRole('Teacher');

        $pivot = SchoolTeacher::create([
            'school_id' => $this->selectedSchoolId,
            'teacher_id' => $teacher->id,
            'is_active' => true,
            'school_email' => $this->school_email ?: null,
        ]);

        $this->createdUserId = $user->id;
        $this->createdSchoolTeacherId = $pivot->id;

        Flux::toast(text: "{$user->first_name} {$user->last_name} added as a teacher at {$this->selectedSchoolName}.", variant: 'success');
    }

    public function verifyUserEmailNow(): void
    {
        $user = $this->createdUser();

        if ($user === null || $user->hasVerifiedEmail()) {
            return;
        }

        $user->markEmailAsVerified();

        Flux::toast(text: "{$user->first_name} {$user->last_name}'s account email manually verified.", variant: 'success');
    }

    public function sendUserVerificationEmail(): void
    {
        $user = $this->createdUser();

        if ($user === null || $user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: "Verification email sent to {$user->first_name} {$user->last_name} at {$user->email}.", variant: 'success');
    }

    public function verifySchoolEmailNow(): void
    {
        $pivot = $this->createdSchoolTeacher();

        if ($pivot === null || blank($pivot->school_email)) {
            return;
        }

        $pivot->update(['verified_at' => now()]);

        Flux::toast(text: "School email verified at {$pivot->school->name}.", variant: 'success');
    }

    public function sendSchoolVerificationEmail(): void
    {
        $pivot = $this->createdSchoolTeacher();

        if ($pivot === null || blank($pivot->school_email) || filled($pivot->verified_at)) {
            return;
        }

        $url = UrlFacade::temporarySignedRoute(
            'school-email.verify',
            now()->addDays(3),
            ['schoolTeacher' => $pivot->id, 'email' => $pivot->school_email],
        );

        Mail::to($pivot->school_email)->send(new SchoolEmailVerificationMail($pivot, $url));

        Flux::toast(text: "Verification email sent to {$pivot->school_email}.", variant: 'success');
    }

    public function addAnother(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->mount();
    }

    private function createdUser(): ?User
    {
        return $this->createdUserId !== null ? User::find($this->createdUserId) : null;
    }

    private function createdSchoolTeacher(): ?SchoolTeacher
    {
        return $this->createdSchoolTeacherId !== null
            ? SchoolTeacher::with('school')->find($this->createdSchoolTeacherId)
            : null;
    }

    public function render(): View
    {
        return view('livewire.founder.add-teacher', [
            'pronouns' => Pronoun::orderBy('sort_order')->get(),
            'geostates' => Geostate::orderBy('name')->get(),
            'counties' => $this->geostate_id !== ''
                ? County::where('geostate_id', $this->geostate_id)->orderBy('name')->get()
                : collect(),
            'schoolSuggestions' => $this->createdUserId === null && $this->selectedSchoolId === null
                ? SchoolMatcher::suggestions(
                    $this->school_search,
                    $this->geostate_id !== '' ? (int) $this->geostate_id : null,
                    $this->zip_code,
                    null,
                )
                : collect(),
            'createdUser' => $this->createdUser(),
            'createdSchoolTeacher' => $this->createdSchoolTeacher(),
        ]);
    }
}
