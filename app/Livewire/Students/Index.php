<?php

declare(strict_types=1);

namespace App\Livewire\Students;

use App\Enums\EmergencyContactRelationship;
use App\Enums\ShirtSize;
use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Models\EmergencyContact;
use App\Models\HomeAddress;
use App\Models\Instrument;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\SchoolTeacherSubject;
use App\Models\Pivots\StudentTeacher;
use App\Models\Pronoun;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\VoicePart;
use App\Support\ClassOfCalculator;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

    public string $schoolFilter = '';

    public ?int $editingRowId = null;

    public bool $isAdding = false;

    public string $add_school_id = '';

    public string $add_grade = '';

    // Teacher relationship — a teacher can claim a student under several subjects
    // at once (school_teacher_subject is many-to-one against school_teacher, and
    // student_teacher is the same shape: one row per student/teacher/school/subject).
    /** @var list<string> */
    public array $edit_subject = [];

    public string $edit_role = '';

    // Profile (users + students)
    public string $edit_first_name = '';

    public string $edit_middle_name = '';

    public string $edit_last_name = '';

    public string $edit_suffix_name = '';

    public string $edit_email = '';

    public string $edit_cell_phone = '';

    public string $edit_pronoun_id = '';

    public string $edit_birthday = '';

    public string $edit_height = '';

    public string $edit_shirt_size = '';

    public string $edit_instrument_id = '';

    public string $edit_voice_part_id = '';

    // Home address (optional, all-or-nothing)
    public string $edit_home_address1 = '';

    public string $edit_home_address2 = '';

    public string $edit_home_city = '';

    public string $edit_home_geo_state = '';

    public string $edit_home_zip_code = '';

    /** @var list<array{id: int|null, name: string, relationship: string, email: string, cell_phone: string, home_phone: string, work_phone: string}> */
    public array $edit_emergency_contacts = [];

    public ?string $emailFallbackNotice = null;

    public ?string $passwordResetNotice = null;

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

    public function updatedSchoolFilter(): void
    {
        $this->resetPage();
    }

    public function deactivate(int $rowId): void
    {
        StudentTeacher::where('id', $rowId)
            ->where('teacher_id', $this->teacher()->id)
            ->update(['is_active' => false]);
    }

    public function remove(int $rowId): void
    {
        StudentTeacher::where('id', $rowId)
            ->where('teacher_id', $this->teacher()->id)
            ->delete();
    }

    public function studentAge(): ?int
    {
        if ($this->edit_birthday === '') {
            return null;
        }

        return Carbon::parse($this->edit_birthday)->age;
    }

    /**
     * False for a null email or one of the system-generated default addresses
     * (Str::uuid().'@studentfolder.info') assigned when no real email is on file.
     */
    public function hasRealEmail(?string $email): bool
    {
        return $email !== null && ! str_ends_with($email, '@studentfolder.info');
    }

    /**
     * "<last_name> <suffix_name>, <first_name> <middle_name>" — distinct from
     * User::sortName (which uses commas between last/suffix and adds an
     * honorific), kept specific to this column per the user's requested format.
     */
    public function studentDisplayName(User $user): string
    {
        $last = collect([$user->last_name, $user->suffix_name])->filter(fn (?string $part) => filled($part))->implode(' ');
        $first = collect([$user->first_name, $user->middle_name])->filter(fn (?string $part) => filled($part))->implode(' ');

        return "{$last}, {$first}";
    }

    public function add(): void
    {
        $this->editingRowId = null;
        $this->isAdding = true;

        $activeSchools = $this->teacher()->schools()->wherePivot('is_active', true)->get();
        $teacherSubjects = $this->teacherDistinctSubjects();

        $this->edit_subject = count($teacherSubjects) === 1 ? $teacherSubjects : [];
        $this->edit_role = TeacherRole::Primary->value;

        $this->edit_first_name = '';
        $this->edit_middle_name = '';
        $this->edit_last_name = '';
        $this->edit_suffix_name = '';
        $this->edit_email = '';
        $this->edit_cell_phone = '';
        $this->edit_pronoun_id = '';

        $this->edit_birthday = '';
        $this->edit_height = '';
        $this->edit_shirt_size = ShirtSize::MED->value;
        $this->edit_instrument_id = '';
        $this->edit_voice_part_id = '';

        $this->edit_home_address1 = '';
        $this->edit_home_address2 = '';
        $this->edit_home_city = '';
        $this->edit_home_geo_state = '';
        $this->edit_home_zip_code = '';

        $this->edit_emergency_contacts = [$this->blankEmergencyContact()];

        $this->add_school_id = $activeSchools->count() === 1 ? (string) $activeSchools->first()->id : '';
        $this->add_grade = '';

        $this->emailFallbackNotice = null;
        $this->passwordResetNotice = null;
        $this->resetErrorBag();
    }

    public function updatedAddSchoolId(): void
    {
        $this->add_grade = '';
    }

    /**
     * @return list<array{grade: int, class_of: int, label: string}>
     */
    public function addGradeOptions(): array
    {
        if ($this->add_school_id === '') {
            return [];
        }

        $school = School::find((int) $this->add_school_id);

        if ($school === null) {
            return [];
        }

        return array_map(
            fn (int $grade) => [
                'grade' => $grade,
                'class_of' => ClassOfCalculator::classOfFromGrade($grade, $school->senior_year),
                'label' => "{$grade}th Grade (Class of ".ClassOfCalculator::classOfFromGrade($grade, $school->senior_year).')',
            ],
            range(12, 4),
        );
    }

    public function edit(int $rowId): void
    {
        $row = $this->teacherRow($rowId)->with(['student.user', 'student.emergencyContacts'])->firstOrFail();
        $student = $row->student;
        $user = $student->user;
        $homeAddress = HomeAddress::where('student_id', $student->id)->first();

        $this->editingRowId = $row->id;
        $this->edit_subject = StudentTeacher::where('student_id', $row->student_id)
            ->where('teacher_id', $this->teacher()->id)
            ->where('school_id', $row->school_id)
            ->get()
            ->map(fn (StudentTeacher $subjectRow) => (string) $subjectRow->getRawOriginal('subject'))
            ->all();
        $this->edit_role = (string) $row->getRawOriginal('role');

        $this->edit_first_name = $user->first_name ?? '';
        $this->edit_middle_name = $user->middle_name ?? '';
        $this->edit_last_name = $user->last_name ?? '';
        $this->edit_suffix_name = $user->suffix_name ?? '';
        $this->edit_email = $user->email ?? '';
        $this->edit_cell_phone = $user->cell_phone ?? '';
        $this->edit_pronoun_id = $user->pronoun_id !== null ? (string) $user->pronoun_id : '';

        $this->edit_birthday = (string) $student->getRawOriginal('birthday');
        $this->edit_height = $student->height !== null ? (string) $student->height : '';
        $this->edit_shirt_size = (string) $student->getRawOriginal('shirt_size');
        $this->edit_instrument_id = $student->instrument_id !== null ? (string) $student->instrument_id : '';
        $this->edit_voice_part_id = $student->voice_part_id !== null ? (string) $student->voice_part_id : '';

        $this->edit_home_address1 = $homeAddress !== null ? $homeAddress->address1 : '';
        $this->edit_home_address2 = $homeAddress !== null ? ($homeAddress->address2 ?? '') : '';
        $this->edit_home_city = $homeAddress !== null ? $homeAddress->city : '';
        $this->edit_home_geo_state = $homeAddress !== null ? $homeAddress->geo_state : '';
        $this->edit_home_zip_code = $homeAddress !== null ? $homeAddress->zip_code : '';

        $this->edit_emergency_contacts = $student->emergencyContacts
            ->map(fn (EmergencyContact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'relationship' => (string) $contact->getRawOriginal('relationship'),
                'email' => $contact->email,
                'cell_phone' => $contact->cell_phone,
                'home_phone' => $contact->home_phone ?? '',
                'work_phone' => $contact->work_phone ?? '',
            ])
            ->all();

        if ($this->edit_emergency_contacts === []) {
            $this->edit_emergency_contacts = [$this->blankEmergencyContact()];
        }

        $this->emailFallbackNotice = null;
        $this->passwordResetNotice = null;
        $this->resetErrorBag();
    }

    public function updatedEditSubject(): void
    {
        if (array_intersect($this->edit_subject, ['band', 'orchestra']) === []) {
            $this->edit_instrument_id = '';
        }

        if (! in_array('chorus', $this->edit_subject, true)) {
            $this->edit_voice_part_id = '';
        }
    }

    public function addEmergencyContactRow(): void
    {
        $this->edit_emergency_contacts[] = $this->blankEmergencyContact();
    }

    public function removeEmergencyContactRow(int $index): void
    {
        unset($this->edit_emergency_contacts[$index]);
        $this->edit_emergency_contacts = array_values($this->edit_emergency_contacts);
    }

    public function resetPassword(): void
    {
        $row = $this->teacherRow($this->editingRowId)->with('student.user')->firstOrFail();
        $user = $row->student->user;

        if ($user->email === null) {
            $this->passwordResetNotice = "This student doesn't have an email address yet, so the password couldn't be reset.";

            return;
        }

        $lowercaseEmail = mb_strtolower($user->email);
        $user->forceFill(['password' => Hash::make($lowercaseEmail)])->save();

        $this->passwordResetNotice = "Password reset to the student's email address: {$lowercaseEmail}.";
    }

    public function saveEdit(): void
    {
        $row = $this->teacherRow($this->editingRowId)->with('student.user')->firstOrFail();
        $student = $row->student;
        $user = $student->user;

        $homeAddressProvided = $this->edit_home_address1 !== ''
            || $this->edit_home_city !== ''
            || $this->edit_home_geo_state !== ''
            || $this->edit_home_zip_code !== '';

        $this->validate($this->profileValidationRules($user->id, $homeAddressProvided), [
            'edit_birthday.before_or_equal' => 'The student must be at least 9 years old.',
            'edit_birthday.after' => 'The student must be no older than 19.',
            'edit_first_name.regex' => 'First name may only contain letters, spaces, hyphens, and apostrophes.',
            'edit_middle_name.regex' => 'Middle name may only contain letters, spaces, hyphens, and apostrophes.',
            'edit_last_name.regex' => 'Last name may only contain letters, spaces, hyphens, and apostrophes.',
        ]);

        // Students aren't required to verify email, and a default address is used
        // whenever none is given or the requested one is already taken (e.g. a
        // shared family address) — see requirements/general/Student Overview.md.
        $attemptedEmail = trim($this->edit_email);
        $fallbackApplied = false;
        $finalEmail = $attemptedEmail;

        if ($attemptedEmail === '' || User::where('email', $attemptedEmail)->where('id', '!=', $user->id)->exists()) {
            $fallbackApplied = $attemptedEmail !== '';
            $finalEmail = Str::uuid().'@studentfolder.info';
        }

        $user->update([
            'first_name' => $this->edit_first_name,
            'middle_name' => $this->edit_middle_name !== '' ? $this->edit_middle_name : null,
            'last_name' => $this->edit_last_name,
            'suffix_name' => $this->edit_suffix_name !== '' ? $this->edit_suffix_name : null,
            'email' => $finalEmail,
            'cell_phone' => $this->edit_cell_phone !== '' ? $this->edit_cell_phone : null,
            'pronoun_id' => $this->edit_pronoun_id !== '' ? (int) $this->edit_pronoun_id : null,
        ]);
        $user->forceFill(['email_unverifiable' => true])->save();

        $student->update([
            'height' => $this->edit_height !== '' ? (int) $this->edit_height : null,
            'birthday' => $this->edit_birthday !== '' ? $this->edit_birthday : null,
            'shirt_size' => $this->edit_shirt_size,
            'instrument_id' => $this->edit_instrument_id !== '' ? (int) $this->edit_instrument_id : null,
            'voice_part_id' => $this->edit_voice_part_id !== '' ? (int) $this->edit_voice_part_id : null,
        ]);

        if ($homeAddressProvided) {
            $student->homeAddress()->updateOrCreate([], [
                'address1' => $this->edit_home_address1,
                'address2' => $this->edit_home_address2 !== '' ? $this->edit_home_address2 : null,
                'city' => $this->edit_home_city,
                'geo_state' => mb_strtoupper($this->edit_home_geo_state),
                'zip_code' => $this->edit_home_zip_code,
            ]);
        } else {
            $student->homeAddress()->delete();
        }

        $this->syncEmergencyContacts($student);
        $this->syncSubjects($row->student_id, $row->teacher_id, $row->school_id);

        // The originally-edited row may have just been deleted above if its subject
        // was deselected — repoint editingRowId at a surviving row for this student
        // so a left-open modal (e.g. after the email-fallback notice) keeps working.
        $this->editingRowId = StudentTeacher::where('student_id', $row->student_id)
            ->where('teacher_id', $row->teacher_id)
            ->where('school_id', $row->school_id)
            ->value('id');

        if ($fallbackApplied) {
            $this->emailFallbackNotice = "\"{$attemptedEmail}\" is already used by another account, so a default address was assigned instead.";
            $this->edit_email = $finalEmail;

            return;
        }

        $this->emailFallbackNotice = null;
        $this->editingRowId = null;
        $this->modal('edit-student')->close();

        Flux::toast(text: "{$user->name} updated successfully.", variant: 'success');
    }

    public function saveAdd(): void
    {
        $homeAddressProvided = $this->edit_home_address1 !== ''
            || $this->edit_home_city !== ''
            || $this->edit_home_geo_state !== ''
            || $this->edit_home_zip_code !== '';

        // Emergency contacts are optional when adding a student — drop any rows the
        // teacher left entirely blank (e.g. the default empty row) before validating,
        // so a skipped contact section doesn't trip the per-field "required" rules.
        $this->edit_emergency_contacts = array_values(array_filter(
            $this->edit_emergency_contacts,
            fn (array $contact) => $contact['name'] !== ''
                || $contact['relationship'] !== ''
                || $contact['email'] !== ''
                || $contact['cell_phone'] !== ''
                || $contact['home_phone'] !== ''
                || $contact['work_phone'] !== ''
        ));

        $activeSchoolIds = $this->teacher()->schools()->wherePivot('is_active', true)->pluck('schools.id')->all();

        $this->validate([
            'add_school_id' => ['required', 'integer', Rule::in($activeSchoolIds)],
            'add_grade' => ['required', 'integer', 'min:4', 'max:12'],
            ...$this->profileValidationRules(null, $homeAddressProvided, emergencyContactsRequired: false),
        ], [
            'edit_birthday.before_or_equal' => 'The student must be at least 9 years old.',
            'edit_birthday.after' => 'The student must be no older than 19.',
            'edit_first_name.regex' => 'First name may only contain letters, spaces, hyphens, and apostrophes.',
            'edit_middle_name.regex' => 'Middle name may only contain letters, spaces, hyphens, and apostrophes.',
            'edit_last_name.regex' => 'Last name may only contain letters, spaces, hyphens, and apostrophes.',
        ]);

        $school = School::findOrFail((int) $this->add_school_id);

        // Students aren't required to verify email, and a default address is used
        // whenever none is given or the requested one is already taken (e.g. a
        // shared family address) — see requirements/general/Student Overview.md.
        $attemptedEmail = trim($this->edit_email);
        $fallbackApplied = false;
        $finalEmail = $attemptedEmail;

        if ($attemptedEmail === '' || User::where('email', $attemptedEmail)->exists()) {
            $fallbackApplied = $attemptedEmail !== '';
            $finalEmail = Str::uuid().'@studentfolder.info';
        }

        $user = User::create([
            'first_name' => $this->edit_first_name,
            'middle_name' => $this->edit_middle_name !== '' ? $this->edit_middle_name : null,
            'last_name' => $this->edit_last_name,
            'suffix_name' => $this->edit_suffix_name !== '' ? $this->edit_suffix_name : null,
            'email' => $finalEmail,
            'password' => null,
            'cell_phone' => $this->edit_cell_phone !== '' ? $this->edit_cell_phone : null,
            'pronoun_id' => (int) $this->edit_pronoun_id,
        ]);
        $user->forceFill(['email_unverifiable' => true])->save();

        $student = Student::create([
            'user_id' => $user->id,
            'height' => $this->edit_height !== '' ? (int) $this->edit_height : null,
            'birthday' => $this->edit_birthday !== '' ? $this->edit_birthday : null,
            'shirt_size' => $this->edit_shirt_size,
            'instrument_id' => $this->edit_instrument_id !== '' ? (int) $this->edit_instrument_id : null,
            'voice_part_id' => $this->edit_voice_part_id !== '' ? (int) $this->edit_voice_part_id : null,
        ]);

        if ($homeAddressProvided) {
            $student->homeAddress()->create([
                'address1' => $this->edit_home_address1,
                'address2' => $this->edit_home_address2 !== '' ? $this->edit_home_address2 : null,
                'city' => $this->edit_home_city,
                'geo_state' => mb_strtoupper($this->edit_home_geo_state),
                'zip_code' => $this->edit_home_zip_code,
            ]);
        }

        $this->syncEmergencyContacts($student);

        SchoolStudent::create([
            'student_id' => $student->id,
            'school_id' => $school->id,
            'is_active' => true,
            'class_of' => ClassOfCalculator::classOfFromGrade((int) $this->add_grade, $school->senior_year),
        ]);

        foreach ($this->edit_subject as $subject) {
            StudentTeacher::create([
                'student_id' => $student->id,
                'teacher_id' => $this->teacher()->id,
                'school_id' => $school->id,
                'subject' => $subject,
                'role' => $this->edit_role,
                'is_active' => true,
            ]);
        }

        $this->isAdding = false;
        $this->editingRowId = StudentTeacher::where('student_id', $student->id)
            ->where('teacher_id', $this->teacher()->id)
            ->where('school_id', $school->id)
            ->value('id');

        if ($fallbackApplied) {
            $this->emailFallbackNotice = "\"{$attemptedEmail}\" is already used by another account, so a default address was assigned instead.";
            $this->edit_email = $finalEmail;

            return;
        }

        $this->emailFallbackNotice = null;
        $this->editingRowId = null;
        $this->modal('edit-student')->close();

        Flux::toast(text: "{$user->name} added successfully.", variant: 'success');
    }

    public function render(): View
    {
        $rows = $this->rows();
        $activeSchools = $this->teacher()->schools()->wherePivot('is_active', true)->orderBy('name')->get();

        return view('livewire.students.index', [
            'rows' => $rows,
            'gradeByRowId' => $this->gradeByRowId($rows->getCollection()),
            'subjectOptions' => Subject::cases(),
            'shirtSizeOptions' => ShirtSize::cases(),
            'relationshipOptions' => EmergencyContactRelationship::cases(),
            'pronouns' => Pronoun::orderBy('sort_order')->get(),
            'instruments' => Instrument::ordered()->get(),
            'voiceParts' => VoicePart::ordered()->get(),
            'addSchoolOptions' => $activeSchools,
            'filterSchools' => $activeSchools,
        ]);
    }

    /**
     * @return array{id: int|null, name: string, relationship: string, email: string, cell_phone: string, home_phone: string, work_phone: string}
     */
    private function blankEmergencyContact(): array
    {
        return ['id' => null, 'name' => '', 'relationship' => '', 'email' => '', 'cell_phone' => '', 'home_phone' => '', 'work_phone' => ''];
    }

    /**
     * Validation rules shared by saveEdit() and saveAdd() — the profile, home
     * address, emergency contact, and subject/role fields are identical for both;
     * only the school/grade fields (add-only), ignored-user-id, and whether an
     * emergency contact is required (optional when adding a new student) differ.
     *
     * @return array<string, array<int, mixed>>
     */
    private function profileValidationRules(?int $ignoreUserId, bool $homeAddressProvided, bool $emergencyContactsRequired = true): array
    {
        $subjectValues = array_map(fn (Subject $subject) => $subject->value, Subject::cases());
        $shirtSizeValues = array_map(fn (ShirtSize $size) => $size->value, ShirtSize::cases());
        $relationshipValues = array_map(fn (EmergencyContactRelationship $relationship) => $relationship->value, EmergencyContactRelationship::cases());

        return [
            'edit_first_name' => ['required', 'string', 'max:255', "regex:/^[\pL\s'-]+$/u"],
            'edit_middle_name' => ['nullable', 'string', 'max:255', "regex:/^[\pL\s'-]+$/u"],
            'edit_last_name' => ['required', 'string', 'max:255', "regex:/^[\pL\s'-]+$/u"],
            'edit_suffix_name' => ['nullable', 'string', 'max:255'],
            'edit_email' => ['nullable', 'email', 'max:255'],
            'edit_cell_phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'cell_phone')->ignore($ignoreUserId)],
            'edit_pronoun_id' => ['required', 'integer', Rule::exists(Pronoun::class, 'id')],
            'edit_birthday' => [
                'nullable', 'date',
                'before_or_equal:'.now()->subYears(9)->format('Y-m-d'),
                'after:'.now()->subYears(20)->format('Y-m-d'),
            ],
            'edit_height' => ['nullable', 'integer', 'min:30', 'max:84'],
            'edit_shirt_size' => ['required', Rule::in($shirtSizeValues)],
            'edit_instrument_id' => ['nullable', 'integer', Rule::exists(Instrument::class, 'id')],
            'edit_voice_part_id' => ['nullable', 'integer', Rule::exists(VoicePart::class, 'id')],

            'edit_home_address1' => [$homeAddressProvided ? 'required' : 'nullable', 'string', 'max:255'],
            'edit_home_address2' => ['nullable', 'string', 'max:255'],
            'edit_home_city' => [$homeAddressProvided ? 'required' : 'nullable', 'string', 'max:255'],
            'edit_home_geo_state' => [$homeAddressProvided ? 'required' : 'nullable', 'string', 'max:2'],
            'edit_home_zip_code' => [$homeAddressProvided ? 'required' : 'nullable', 'string', 'max:10'],

            'edit_emergency_contacts' => [$emergencyContactsRequired ? 'required' : 'nullable', 'array', $emergencyContactsRequired ? 'min:1' : 'min:0'],
            'edit_emergency_contacts.*.name' => ['required', 'string', 'max:255'],
            'edit_emergency_contacts.*.relationship' => ['required', Rule::in($relationshipValues)],
            'edit_emergency_contacts.*.email' => ['required', 'email', 'max:255'],
            'edit_emergency_contacts.*.cell_phone' => ['required', 'string', 'max:20'],
            'edit_emergency_contacts.*.home_phone' => ['nullable', 'string', 'max:20'],
            'edit_emergency_contacts.*.work_phone' => ['nullable', 'string', 'max:20'],

            'edit_subject' => ['required', 'array', 'min:1'],
            'edit_subject.*' => [Rule::in($subjectValues)],
            'edit_role' => ['required', Rule::in([TeacherRole::Primary->value, TeacherRole::Coteacher->value])],
        ];
    }

    private function syncEmergencyContacts(Student $student): void
    {
        $keptIds = [];

        foreach ($this->edit_emergency_contacts as $contact) {
            $attributes = [
                'name' => $contact['name'],
                'relationship' => $contact['relationship'],
                'email' => $contact['email'],
                'cell_phone' => $contact['cell_phone'],
                'home_phone' => $contact['home_phone'] !== '' ? $contact['home_phone'] : null,
                'work_phone' => $contact['work_phone'] !== '' ? $contact['work_phone'] : null,
            ];

            if ($contact['id'] !== null) {
                EmergencyContact::where('id', $contact['id'])->where('student_id', $student->id)->update($attributes);
                $keptIds[] = $contact['id'];
            } else {
                $keptIds[] = $student->emergencyContacts()->create($attributes)->id;
            }
        }

        $student->emergencyContacts()->whereNotIn('id', $keptIds)->delete();
    }

    /**
     * One student_teacher row exists per selected subject. Existing rows for
     * subjects still selected get their role updated; rows for newly-selected
     * subjects are created (active by default); rows for deselected subjects
     * are removed.
     */
    private function syncSubjects(int $studentId, int $teacherId, int $schoolId): void
    {
        $existingRows = StudentTeacher::where('student_id', $studentId)
            ->where('teacher_id', $teacherId)
            ->where('school_id', $schoolId)
            ->get();

        $existingBySubject = $existingRows->keyBy(fn (StudentTeacher $row) => $row->getRawOriginal('subject'));

        foreach ($this->edit_subject as $subject) {
            if ($existingBySubject->has($subject)) {
                $existingBySubject[$subject]->update(['role' => $this->edit_role]);
            } else {
                StudentTeacher::create([
                    'student_id' => $studentId,
                    'teacher_id' => $teacherId,
                    'school_id' => $schoolId,
                    'subject' => $subject,
                    'role' => $this->edit_role,
                    'is_active' => true,
                ]);
            }
        }

        foreach ($existingRows as $existingRow) {
            if (! in_array($existingRow->getRawOriginal('subject'), $this->edit_subject, true)) {
                $existingRow->delete();
            }
        }
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }

    /**
     * Distinct subjects this teacher is set up to teach across their active schools,
     * used to default the Add-student Subject field when there's no ambiguity.
     *
     * @return list<string>
     */
    private function teacherDistinctSubjects(): array
    {
        $schoolTeacherIds = SchoolTeacher::where('teacher_id', $this->teacher()->id)
            ->where('is_active', true)
            ->pluck('id');

        return SchoolTeacherSubject::whereIn('school_teacher_id', $schoolTeacherIds)
            ->get()
            ->map(fn (SchoolTeacherSubject $row) => $row->getRawOriginal('subject'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Builder<StudentTeacher>
     */
    private function teacherRow(?int $rowId)
    {
        return StudentTeacher::where('id', $rowId)->where('teacher_id', $this->teacher()->id);
    }

    /**
     * @return LengthAwarePaginator<int, StudentTeacher>
     */
    private function rows(): LengthAwarePaginator
    {
        $query = StudentTeacher::query()
            ->select('student_teacher.*')
            ->where('student_teacher.teacher_id', $this->teacher()->id)
            ->join('students', 'students.id', '=', 'student_teacher.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->join('schools', 'schools.id', '=', 'student_teacher.school_id')
            // Gates the roster to schools the teacher is still actively at — a
            // deactivated school relationship (Schools index "Deactivate") hides
            // that school's students here too, without touching their own rows.
            ->join('school_teacher', function (JoinClause $join) {
                $join->on('school_teacher.school_id', '=', 'student_teacher.school_id')
                    ->on('school_teacher.teacher_id', '=', 'student_teacher.teacher_id');
            })
            ->where('school_teacher.is_active', true)
            ->leftJoin('voice_parts', 'voice_parts.id', '=', 'students.voice_part_id')
            ->with(['student.user', 'student.homeAddress', 'student.emergencyContacts', 'student.voicePart', 'school']);

        if ($this->search !== '') {
            $query->where(fn ($q) => $q->where('users.first_name', 'like', "%{$this->search}%")
                ->orWhere('users.last_name', 'like', "%{$this->search}%"));
        }

        if ($this->schoolFilter !== '') {
            $query->where('student_teacher.school_id', (int) $this->schoolFilter);
        }

        if ($this->sortColumn === 'grade') {
            return $this->paginateSortedByGrade($query);
        }

        match ($this->sortColumn) {
            'school' => $query->orderBy('schools.name', $this->sortDirection),
            'subject' => $query->orderBy('student_teacher.subject', $this->sortDirection),
            'voice_part' => $query->orderBy('voice_parts.name', $this->sortDirection),
            default => $query->orderBy('users.last_name', $this->sortDirection)->orderBy('users.first_name', $this->sortDirection),
        };

        return $query->paginate(15);
    }

    /**
     * Grade isn't a stored column — it's computed from school_student.class_of and
     * the school's senior_year (itself date-dependent, not a column — see
     * School::getSeniorYearAttribute()) — so sorting by it can't be pushed into
     * SQL without duplicating that logic there. Instead the full filtered/searched
     * result set is pulled into memory, sorted in PHP, then sliced into a page
     * manually so it still behaves like a normal Eloquent paginator.
     *
     * @param  Builder<StudentTeacher>  $query
     */
    private function paginateSortedByGrade(Builder $query): LengthAwarePaginator
    {
        $allRows = $query->get();
        $grades = $this->gradeByRowId($allRows);

        $sorted = $allRows->sort(function (StudentTeacher $a, StudentTeacher $b) use ($grades) {
            $gradeA = $grades[$a->id] ?? -1;
            $gradeB = $grades[$b->id] ?? -1;

            return $this->sortDirection === 'desc' ? $gradeB <=> $gradeA : $gradeA <=> $gradeB;
        })->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 15;

        return new LengthAwarePaginator(
            $sorted->slice(($page - 1) * $perPage, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );
    }

    /**
     * Grade depends on the student's class_of at the specific school this row
     * belongs to, not necessarily their currently-active school, so it's looked
     * up per (student, school) pair rather than via Student::getGradeAttribute().
     *
     * @param  Collection<int, StudentTeacher>  $rows
     * @return array<int, int|null>
     */
    private function gradeByRowId(Collection $rows): array
    {
        $studentIds = $rows->pluck('student_id');
        $schoolIds = $rows->pluck('school_id');

        $schoolStudents = SchoolStudent::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('school_id', $schoolIds)
            ->get()
            ->keyBy(fn (SchoolStudent $schoolStudent) => $schoolStudent->student_id.'-'.$schoolStudent->school_id);

        $grades = [];

        foreach ($rows as $row) {
            $schoolStudent = $schoolStudents->get($row->student_id.'-'.$row->school_id);

            $grades[$row->id] = $schoolStudent !== null
                ? ClassOfCalculator::gradeFromClassOf((int) $schoolStudent->class_of, $row->school->senior_year)
                : null;
        }

        return $grades;
    }
}
