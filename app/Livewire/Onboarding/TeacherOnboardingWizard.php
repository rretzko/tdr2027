<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Enums\EventInvitationStatus;
use App\Enums\SchoolType;
use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Models\County;
use App\Models\Event;
use App\Models\EventInvitationRequest;
use App\Models\Geostate;
use App\Models\Organization;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\SchoolTeacherSubject;
use App\Models\Pivots\StudentTeacher;
use App\Models\Pivots\TeacherSupervisor;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ClassOfCalculator;
use App\Support\SchoolMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class TeacherOnboardingWizard extends Component
{
    public int $step = 1;

    // Step 1 — link school/studio
    public string $geostate_id = '';

    public string $zip_code = '';

    public string $school_search = '';

    public bool $creatingNewSchool = false;

    public string $new_school_name = '';

    public string $new_school_type = 'school';

    public string $new_school_city = '';

    public string $new_school_zip_code = '';

    public string $new_school_county_id = '';

    // Step 2 — role & subjects
    public string $role = '';

    public bool $isReplacingTeacher = false;

    public string $replacing_teacher_name = '';

    /** @var list<string> */
    public array $subjects = [];

    // Step 3 — students
    /** @var list<int> */
    public array $claimedStudentIds = [];

    /** @var array<int, string> */
    public array $claimedStudentSubject = [];

    /** @var list<array{first_name: string, last_name: string, class_of: string, subject: string}> */
    public array $newStudents = [];

    // Step 4 — organizations
    /** @var list<int> */
    public array $selectedOrganizationIds = [];

    /** @var array<int, string> */
    public array $supervisorName = [];

    /** @var array<int, string> */
    public array $supervisorEmail = [];

    /** @var array<int, string> */
    public array $supervisorCellPhone = [];

    // Step 5 — events
    /** @var list<int> */
    public array $selectedEventIds = [];

    public function mount(): void
    {
        $teacher = Auth::user()->teacher;

        if ($teacher === null || $teacher->onboarding_completed_at !== null) {
            $this->redirect(route('dashboard'), navigate: true);

            return;
        }

        $this->step = $teacher->onboarding_step;

        if ($this->geostate_id === '') {
            $newJersey = Geostate::where('name', 'New Jersey')->first();
            $this->geostate_id = $newJersey !== null ? (string) $newJersey->id : '';
        }

        // Steps after 2 depend on role/subjects already chosen there. If the component
        // remounts fresh (page reload, returning later) past step 2, that in-memory
        // state would otherwise be lost even though it was already persisted.
        $pivot = $this->currentSchoolTeacher();

        if ($pivot !== null) {
            $this->role = (string) ($pivot->getRawOriginal('role') ?? '');
            $this->isReplacingTeacher = $pivot->replacing_teacher_name !== null;
            $this->replacing_teacher_name = $pivot->replacing_teacher_name ?? '';
            $this->subjects = SchoolTeacherSubject::where('school_teacher_id', $pivot->id)
                ->pluck('subject')
                ->map(fn (Subject $subject) => $subject->value)
                ->all();
        }
    }

    public function back(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function selectSchool(int $schoolId): void
    {
        $this->linkSchool(School::findOrFail($schoolId));
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

        $this->linkSchool($school);
    }

    private function linkSchool(School $school): void
    {
        SchoolTeacher::firstOrCreate(
            ['school_id' => $school->id, 'teacher_id' => $this->teacher()->id],
            ['is_active' => true]
        );

        $this->advanceTo(2);
    }

    public function saveRoleAndSubjects(): void
    {
        $subjectValues = array_map(fn (Subject $subject) => $subject->value, Subject::cases());

        $this->validate([
            'role' => ['required', Rule::in([TeacherRole::Primary->value, TeacherRole::Coteacher->value])],
            'replacing_teacher_name' => [$this->isReplacingTeacher ? 'required' : 'nullable', 'string', 'max:255'],
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*' => [Rule::in($subjectValues)],
        ]);

        $pivot = $this->currentSchoolTeacher();

        $pivot->update([
            'role' => $this->role,
            'replacing_teacher_name' => $this->isReplacingTeacher ? $this->replacing_teacher_name : null,
        ]);

        SchoolTeacherSubject::where('school_teacher_id', $pivot->id)
            ->whereNotIn('subject', $this->subjects)
            ->delete();

        foreach ($this->subjects as $subject) {
            SchoolTeacherSubject::firstOrCreate([
                'school_teacher_id' => $pivot->id,
                'subject' => $subject,
            ]);
        }

        $this->advanceTo(3);
    }

    public function addNewStudentRow(): void
    {
        $this->newStudents[] = ['first_name' => '', 'last_name' => '', 'class_of' => '', 'subject' => ''];
    }

    public function removeNewStudentRow(int $index): void
    {
        unset($this->newStudents[$index]);
        $this->newStudents = array_values($this->newStudents);
    }

    public function saveStudents(): void
    {
        $school = $this->currentSchool();
        $teacher = $this->teacher();
        $defaultSubject = count($this->subjects) === 1 ? $this->subjects[0] : null;
        $subjectValues = array_map(fn (Subject $subject) => $subject->value, Subject::cases());

        $this->validate([
            'claimedStudentSubject.*' => ['nullable', Rule::in($subjectValues)],
            'newStudents.*.first_name' => ['nullable', 'string', 'max:255'],
            'newStudents.*.last_name' => ['nullable', 'string', 'max:255'],
            'newStudents.*.class_of' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'newStudents.*.subject' => ['nullable', Rule::in($subjectValues)],
        ]);

        foreach ($this->claimedStudentIds as $studentId) {
            if (($this->claimedStudentSubject[$studentId] ?? $defaultSubject) === null) {
                $this->addError('claimedStudentSubject.'.$studentId, 'Choose a subject for this student.');
            }
        }

        foreach ($this->newStudents as $index => $row) {
            $hasName = filled($row['first_name']) || filled($row['last_name']);

            if (! $hasName) {
                continue;
            }

            if (blank($row['first_name'])) {
                $this->addError("newStudents.{$index}.first_name", 'First name is required.');
            }

            if (blank($row['last_name'])) {
                $this->addError("newStudents.{$index}.last_name", 'Last name is required.');
            }

            if (blank($row['class_of'])) {
                $this->addError("newStudents.{$index}.class_of", 'Choose a class of year.');
            }

            if (blank($row['subject']) && $defaultSubject === null) {
                $this->addError("newStudents.{$index}.subject", 'Choose a subject for this student.');
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        foreach ($this->claimedStudentIds as $studentId) {
            $subject = $this->claimedStudentSubject[$studentId] ?? $defaultSubject;

            StudentTeacher::firstOrCreate(
                [
                    'student_id' => $studentId,
                    'teacher_id' => $teacher->id,
                    'school_id' => $school->id,
                    'subject' => $subject,
                ],
                ['role' => TeacherRole::Primary->value, 'is_active' => true]
            );
        }

        foreach ($this->newStudents as $row) {
            if (blank($row['first_name']) || blank($row['last_name'])) {
                continue;
            }

            $user = User::create([
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => Str::uuid().'@studentfolder.info',
                'password' => null,
                'pronoun_id' => null,
            ]);

            $user->forceFill(['email_unverifiable' => true])->save();

            $student = Student::create(['user_id' => $user->id]);

            SchoolStudent::create([
                'student_id' => $student->id,
                'school_id' => $school->id,
                'is_active' => true,
                'class_of' => (int) $row['class_of'],
            ]);

            StudentTeacher::create([
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'school_id' => $school->id,
                'subject' => $row['subject'] ?: $defaultSubject,
                'role' => TeacherRole::Primary->value,
                'is_active' => true,
            ]);
        }

        $this->advanceTo(4);
    }

    public function saveOrganizations(): void
    {
        $rules = [];

        // Supervisor contact info is optional for now — whether it's required will
        // eventually be configured per Version (not yet built).
        foreach ($this->selectedOrganizationIds as $orgId) {
            $rules["supervisorName.{$orgId}"] = ['nullable', 'string', 'max:255'];
            $rules["supervisorEmail.{$orgId}"] = ['nullable', 'email', 'max:255'];
            $rules["supervisorCellPhone.{$orgId}"] = ['nullable', 'string', 'min:10', 'max:20'];
        }

        if ($rules !== []) {
            $this->validate($rules);
        }

        foreach ($this->selectedOrganizationIds as $orgId) {
            $cellPhone = $this->supervisorCellPhone[$orgId] ?? '';

            TeacherSupervisor::firstOrCreate(
                ['organization_id' => $orgId, 'teacher_id' => $this->teacher()->id],
                [
                    'supervisor_name' => ($this->supervisorName[$orgId] ?? '') ?: null,
                    'supervisor_email' => ($this->supervisorEmail[$orgId] ?? '') ?: null,
                    'supervisory_cell_phone' => $cellPhone !== '' ? preg_replace('/\D/', '', $cellPhone) : null,
                ]
            );
        }

        $this->advanceTo(5);
    }

    public function requestEventInvitations(): void
    {
        $teacherId = $this->teacher()->id;

        foreach ($this->selectedEventIds as $eventId) {
            EventInvitationRequest::firstOrCreate(
                ['event_id' => $eventId, 'teacher_id' => $teacherId],
                ['status' => EventInvitationStatus::Pending]
            );
        }

        $this->advanceTo(6);
    }

    public function finish(): void
    {
        $this->teacher()->update(['onboarding_completed_at' => now()]);

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        $school = $this->currentSchool();

        return view('livewire.onboarding.teacher-onboarding-wizard', [
            'geostates' => Geostate::orderBy('name')->get(),
            'counties' => $this->geostate_id !== ''
                ? County::where('geostate_id', $this->geostate_id)->orderBy('name')->get()
                : collect(),
            'schoolSuggestions' => $this->step === 1
                ? SchoolMatcher::suggestions(
                    $this->school_search,
                    $this->geostate_id !== '' ? (int) $this->geostate_id : null,
                    $this->zip_code,
                    null,
                )
                : collect(),
            'currentSchool' => $school,
            'existingStudents' => $this->step === 3 && $school !== null
                ? $this->unclaimedStudents($school)
                : collect(),
            'classOfOptions' => $this->step === 3 && $school !== null
                ? $this->classOfOptions($school)
                : [],
            'organizationTree' => $this->step === 4 ? $this->organizationTree() : [],
            'openEvents' => $this->step === 5 ? $this->openEvents() : collect(),
            'subjectOptions' => Subject::cases(),
        ]);
    }

    /**
     * Builds a parent/child organization tree, alphabetical by name at every level,
     * so step 4 can render sub-organizations indented under their parent instead of
     * a flat list. Plain arrays (not Collections) are used for the recursive nesting
     * since Collection's generic value type is invariant and can't express a
     * self-referential array shape.
     *
     * @return list<array{organization: Organization, children: array}>
     */
    private function organizationTree(): array
    {
        return $this->organizationChildrenOf(Organization::orderBy('name')->get(), null);
    }

    /**
     * @param  Collection<int, Organization>  $organizations
     * @return list<array{organization: Organization, children: array}>
     */
    private function organizationChildrenOf(Collection $organizations, ?int $parentId): array
    {
        return $organizations
            ->where('parent_id', $parentId)
            ->map(fn (Organization $organization) => [
                'organization' => $organization,
                'children' => $this->organizationChildrenOf($organizations, $organization->id),
            ])
            ->values()
            ->all();
    }

    /**
     * Grades 12 down to 4 and the graduating year each currently maps to, so the
     * wizard can offer a "class of" dropdown instead of a free-text year field.
     *
     * @return list<array{grade: int, class_of: int, label: string}>
     */
    private function classOfOptions(School $school): array
    {
        $seniorYear = $school->senior_year;

        return array_map(
            fn (int $grade) => [
                'grade' => $grade,
                'class_of' => ClassOfCalculator::classOfFromGrade($grade, $seniorYear),
                'label' => "{$grade}th Grade (Class of ".ClassOfCalculator::classOfFromGrade($grade, $seniorYear).')',
            ],
            range(12, 4),
        );
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }

    private function currentSchool(): ?School
    {
        return $this->teacher()->schools()->first();
    }

    private function currentSchoolTeacher(): ?SchoolTeacher
    {
        $school = $this->currentSchool();

        if ($school === null) {
            return null;
        }

        return SchoolTeacher::where('school_id', $school->id)
            ->where('teacher_id', $this->teacher()->id)
            ->first();
    }

    /**
     * @return Collection<int, Student>
     */
    private function unclaimedStudents(School $school): Collection
    {
        $teacherId = $this->teacher()->id;

        return Student::with('user')
            ->whereHas('schools', fn ($query) => $query->where('schools.id', $school->id))
            ->whereDoesntHave('teachers', fn ($query) => $query->where('teachers.id', $teacherId))
            ->get();
    }

    /**
     * @return Collection<int, Event>
     */
    private function openEvents(): Collection
    {
        $organizationIds = TeacherSupervisor::where('teacher_id', $this->teacher()->id)->pluck('organization_id');

        if ($organizationIds->isEmpty()) {
            return collect();
        }

        return Event::where('is_open', true)->whereIn('organization_id', $organizationIds)->get();
    }

    private function advanceTo(int $step): void
    {
        $this->teacher()->update(['onboarding_step' => $step]);
        $this->step = $step;
    }
}
