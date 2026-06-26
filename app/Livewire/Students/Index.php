<?php

declare(strict_types=1);

namespace App\Livewire\Students;

use App\Enums\ClaimStatus;
use App\Enums\EmergencyContactRelationship;
use App\Enums\SchoolType;
use App\Enums\ShirtSize;
use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Mail\StudentClaimMail;
use App\Models\County;
use App\Models\EmergencyContact;
use App\Models\Geostate;
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
use App\Support\SchoolMatcher;
use App\Support\StudentMatcher;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL as UrlFacade;
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

    // Read-only context shown in the Edit modal for the row's own school — Add
    // shows this via the add_school_id select instead, so these are edit-only.
    public string $editingSchoolName = '';

    public bool $editingSchoolIsStudio = false;

    // The student's actual/home school — only collected when the row's school is
    // a studio (see Student::homeSchool()). Mirrors the suggest-or-add-new pattern
    // used by Schools index's "School name" field, scoped to type=school.
    public string $edit_home_school_name = '';

    public string $edit_home_school_id = '';

    public bool $edit_home_school_confirmed_new = false;

    public string $edit_home_school_city = '';

    public string $edit_home_school_zip_code = '';

    public string $edit_home_school_geostate_id = '';

    public string $edit_home_school_county_id = '';

    // Possible-duplicate matches against the name/birthday/contact info typed
    // into the Add-student form (see App\Support\StudentMatcher) — gated to
    // isAdding, since an existing row's identity is already settled. A match
    // is "resolved" once its student id lands in dismissedStudentMatchIds (the
    // teacher said "not this person") or attachingStudentId (they confirmed it).
    /** @var list<int> */
    public array $dismissedStudentMatchIds = [];

    public ?int $attachingStudentId = null;

    public string $attachingStudentName = '';

    public string $attachingStudentSchoolName = '';

    public ?int $attachingStudentGrade = null;

    // Cross-org claim — a matched student already enrolled at a *different*
    // school/studio than the one being added to. Unlike attachingStudentId,
    // submitting this doesn't attach immediately (see submitStudentClaim())
    // unless claimWillAutoApprove is true, meaning nobody currently has an
    // active teacher relationship with this student in the system.
    public ?int $claimingStudentId = null;

    public string $claimingStudentName = '';

    public string $claimingStudentSchoolName = '';

    public string $claim_grade = '';

    public bool $claimWillAutoApprove = false;

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

    public string $edit_grade = '';

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

        $this->editingSchoolName = '';
        $this->editingSchoolIsStudio = false;
        $this->resetHomeSchoolFields();

        $this->dismissedStudentMatchIds = [];
        $this->resetAttachingStudent();
        $this->resetStudentClaim();

        $this->emailFallbackNotice = null;
        $this->passwordResetNotice = null;
        $this->resetErrorBag();
    }

    public function updatedAddSchoolId(): void
    {
        $this->add_grade = '';
    }

    public function addSchoolType(): ?SchoolType
    {
        if ($this->add_school_id === '') {
            return null;
        }

        $school = School::find((int) $this->add_school_id);

        if ($school === null) {
            return null;
        }

        return SchoolType::from($school->getRawOriginal('type'));
    }

    /**
     * Whether the school in scope for the open modal — the picked add_school_id
     * while adding, or the row's own school while editing — is a studio, which
     * gates the Student's School field and the "Studio" vs "School" labels.
     */
    public function isStudioContext(): bool
    {
        return $this->isAdding ? $this->addSchoolType() === SchoolType::Studio : $this->editingSchoolIsStudio;
    }

    public function schoolOrStudioLabel(): string
    {
        return $this->isStudioContext() ? 'Studio' : 'School';
    }

    /**
     * Possible-duplicate matches against what's been typed so far — only
     * meaningful while adding (an existing row's identity is already
     * settled) and only once a name has been typed. Suppressed once the
     * teacher has actually committed to attaching to one of them, so the
     * suggestion list doesn't keep competing with the attach-mode form.
     *
     * Excludes a candidate the requesting teacher already has *any*
     * student_teacher row for at this school (active or not) — that's not an
     * identity ambiguity to resolve, it's just their own existing roster
     * entry, which Edit already handles. Without this, "attach" on such a
     * match would try to create a row that already exists and violate
     * student_teacher's unique constraint.
     *
     * @return Collection<int, array{student: Student, tier: string}>
     */
    public function studentMatcherMatches(): Collection
    {
        if (! $this->isAdding || $this->attachingStudentId !== null || $this->claimingStudentId !== null) {
            return collect();
        }

        $contact = $this->edit_emergency_contacts[0] ?? null;

        return StudentMatcher::suggestions(
            $this->edit_first_name,
            $this->edit_last_name,
            $this->edit_birthday !== '' ? $this->edit_birthday : null,
            $contact['email'] ?? null,
            $contact['cell_phone'] ?? null,
        )->reject(fn (array $match) => $this->teacherAlreadyHasStudentAtAddSchool($match['student']->id));
    }

    private function teacherAlreadyHasStudentAtAddSchool(int $studentId): bool
    {
        if ($this->add_school_id === '') {
            return false;
        }

        return StudentTeacher::where('student_id', $studentId)
            ->where('teacher_id', $this->teacher()->id)
            ->where('school_id', (int) $this->add_school_id)
            ->exists();
    }

    /**
     * @return Collection<int, array{student: Student, tier: string}>
     */
    public function unresolvedStudentMatches(): Collection
    {
        return $this->studentMatcherMatches()
            ->reject(fn (array $match) => in_array($match['student']->id, $this->dismissedStudentMatchIds, true));
    }

    /**
     * Whether a matched student already has an active enrollment at the
     * school/studio being added to — the trust boundary that lets the new
     * teacher attach directly, the same way a verified same-school teacher
     * already inherits a replaced teacher's students (see
     * ReplacedTeacherStudentTransfer). A match at a *different* school/studio
     * isn't attachable yet — that needs the cross-org claim workflow.
     */
    public function studentMatchIsSameSchool(Student $student): bool
    {
        if ($this->add_school_id === '') {
            return false;
        }

        return SchoolStudent::where('student_id', $student->id)
            ->where('school_id', (int) $this->add_school_id)
            ->where('is_active', true)
            ->exists();
    }

    public function studentMatchCurrentSchoolName(Student $student): string
    {
        $school = $student->schools()->wherePivot('is_active', true)->first();

        return $school !== null ? $school->name : 'no current school on file';
    }

    public function studentMatchGrade(Student $student): ?int
    {
        $school = $student->schools()->wherePivot('is_active', true)->first();

        if ($school === null) {
            return null;
        }

        $schoolStudent = SchoolStudent::where('student_id', $student->id)->where('school_id', $school->id)->first();

        if ($schoolStudent === null) {
            return null;
        }

        return ClassOfCalculator::gradeFromClassOf((int) $schoolStudent->class_of, $school->senior_year);
    }

    public function dismissStudentMatch(int $studentId): void
    {
        if (! in_array($studentId, $this->dismissedStudentMatchIds, true)) {
            $this->dismissedStudentMatchIds[] = $studentId;
        }
    }

    /**
     * Enters attach mode for a matched student already enrolled at the school
     * being added to — switches the modal from "create a new student" to
     * "claim this existing one under a subject," since the profile, address,
     * and contact fields below all belong to a record that already exists.
     */
    public function selectStudentMatch(int $studentId): void
    {
        $student = Student::with('user')->findOrFail($studentId);

        $this->attachingStudentId = $student->id;
        $this->attachingStudentName = $student->user->name;
        $this->attachingStudentSchoolName = $this->studentMatchCurrentSchoolName($student);
        $this->attachingStudentGrade = $this->studentMatchGrade($student);
    }

    public function cancelAttachExistingStudent(): void
    {
        $this->resetAttachingStudent();
    }

    /**
     * Claims an already-existing student for this teacher under the selected
     * subject(s), instead of creating a new Student record. Only reachable
     * for a same-school match (see studentMatchIsSameSchool()) — the existing
     * record's own profile, contacts, and address are left untouched.
     *
     * studentMatcherMatches() already excludes a student the teacher has any
     * row for at this school, but that's a UI nicety, not a guarantee (e.g. a
     * second roster claim could appear between the suggestion rendering and
     * this submit) — so each subject is still checked individually here
     * rather than blindly inserting, to avoid student_teacher's unique
     * (student_id, teacher_id, school_id, subject) constraint ever rejecting
     * the request outright. An existing inactive row is reactivated instead
     * of left stale; an existing active row for that subject is left as-is.
     */
    public function attachExistingStudent(): void
    {
        $student = Student::with('user')->findOrFail($this->attachingStudentId);

        $this->validate([
            'edit_subject' => ['required', 'array', 'min:1'],
            'edit_subject.*' => [Rule::in(array_map(fn (Subject $subject) => $subject->value, Subject::cases()))],
            'edit_role' => ['required', Rule::in([TeacherRole::Primary->value, TeacherRole::Coteacher->value])],
        ]);

        $changedAnything = false;

        foreach ($this->edit_subject as $subject) {
            $existing = StudentTeacher::where('student_id', $student->id)
                ->where('teacher_id', $this->teacher()->id)
                ->where('school_id', (int) $this->add_school_id)
                ->where('subject', $subject)
                ->first();

            if ($existing !== null) {
                if (! $existing->is_active) {
                    $existing->update(['is_active' => true, 'role' => $this->edit_role]);
                    $changedAnything = true;
                }

                continue;
            }

            StudentTeacher::create([
                'student_id' => $student->id,
                'teacher_id' => $this->teacher()->id,
                'school_id' => (int) $this->add_school_id,
                'subject' => $subject,
                'role' => $this->edit_role,
                'is_active' => true,
            ]);
            $changedAnything = true;
        }

        $this->isAdding = false;
        $this->resetAttachingStudent();
        $this->editingRowId = StudentTeacher::where('student_id', $student->id)
            ->where('teacher_id', $this->teacher()->id)
            ->where('school_id', (int) $this->add_school_id)
            ->value('id');

        $this->modal('edit-student')->close();

        Flux::toast(
            text: $changedAnything
                ? "{$student->user->name} added to your roster."
                : "{$student->user->name} is already on your roster under the selected subject(s) — no changes made.",
            variant: $changedAnything ? 'success' : 'warning',
        );
    }

    /**
     * Enters claim-request mode for a matched student enrolled at a
     * *different* school/studio — unlike selectStudentMatch(), submitting
     * this doesn't attach immediately (see submitStudentClaim()).
     */
    public function selectStudentClaim(int $studentId): void
    {
        $student = Student::with('user')->findOrFail($studentId);
        $schoolSubjects = $this->subjectsForAddSchool();

        $this->claimingStudentId = $student->id;
        $this->claimingStudentName = $student->user->name;
        $this->claimingStudentSchoolName = $this->studentMatchCurrentSchoolName($student);
        $this->claim_grade = $this->add_grade;
        $this->edit_subject = count($schoolSubjects) === 1 ? $schoolSubjects : [];
        $this->claimWillAutoApprove = $this->activeTeachersFor($studentId)->isEmpty();
    }

    public function cancelStudentClaim(): void
    {
        $this->resetStudentClaim();
    }

    /**
     * Requests this teacher be added as a teacher for a student who already
     * belongs to a different school/studio. If nobody currently has an active
     * claim on the student anywhere, there's no one to ask, so this attaches
     * immediately instead of creating a pending request (see the "zero active
     * teachers" decision in docs/plans/student-duplicate-prevention.md) —
     * otherwise every existing active teacher is emailed an approve/deny link
     * and the new row(s) sit as pending until one of them responds.
     */
    public function submitStudentClaim(): void
    {
        $student = Student::with('user')->findOrFail($this->claimingStudentId);
        $school = School::findOrFail((int) $this->add_school_id);

        $this->validate([
            'edit_subject' => ['required', 'array', 'min:1'],
            'edit_subject.*' => [Rule::in(array_map(fn (Subject $subject) => $subject->value, Subject::cases()))],
            'edit_role' => ['required', Rule::in([TeacherRole::Primary->value, TeacherRole::Coteacher->value])],
            'claim_grade' => ['required', 'integer', 'min:4', 'max:12'],
        ]);

        $classOf = ClassOfCalculator::classOfFromGrade((int) $this->claim_grade, $school->senior_year);
        $approvers = $this->activeTeachersFor($student->id);
        $autoApprove = $approvers->isEmpty();

        if ($autoApprove) {
            SchoolStudent::firstOrCreate(
                ['student_id' => $student->id, 'school_id' => $school->id],
                ['is_active' => true, 'class_of' => $classOf]
            );
        }

        foreach ($this->edit_subject as $subject) {
            StudentTeacher::create([
                'student_id' => $student->id,
                'teacher_id' => $this->teacher()->id,
                'school_id' => $school->id,
                'subject' => $subject,
                'role' => $this->edit_role,
                'is_active' => $autoApprove,
                'claim_status' => $autoApprove ? ClaimStatus::Approved->value : ClaimStatus::Pending->value,
                'pending_class_of' => $autoApprove ? null : $classOf,
            ]);
        }

        $this->isAdding = false;
        $this->resetStudentClaim();
        $this->editingRowId = StudentTeacher::where('student_id', $student->id)
            ->where('teacher_id', $this->teacher()->id)
            ->where('school_id', $school->id)
            ->value('id');

        $this->modal('edit-student')->close();

        if ($autoApprove) {
            Flux::toast(text: "{$student->user->name} added to your roster.", variant: 'success');

            return;
        }

        $this->sendClaimRequestEmails($approvers, $student, $school);

        Flux::toast(
            text: "Request sent — {$student->user->name} will be added once their current teacher approves.",
            variant: 'success',
        );
    }

    /**
     * Distinct teachers with an active claim on this student anywhere in the
     * system — the people who need to approve (or could deny) a cross-org
     * request, since they're the ones who'd otherwise lose any say in who
     * else gets access to this student's profile.
     *
     * @return Collection<int, Teacher>
     */
    /**
     * Teachers who currently have an active roster claim on this student
     * (any school, any subject) — the people who need to approve a cross-org
     * request before the requesting teacher gains access to the student's
     * profile. Uses a direct subquery on student_teacher rather than
     * whereHas/wherePivot, which can behave unexpectedly with custom pivot
     * classes (::using()) in a whereHas callback.
     */
    private function activeTeachersFor(int $studentId): Collection
    {
        return Teacher::query()
            ->whereIn('id', function ($q) use ($studentId) {
                $q->select('teacher_id')
                    ->from('student_teacher')
                    ->where('student_id', $studentId)
                    ->where('is_active', true);
            })
            ->with('user')
            ->get();
    }

    /**
     * @param  Collection<int, Teacher>  $approvers
     */
    private function sendClaimRequestEmails(Collection $approvers, Student $student, School $school): void
    {
        $requestingTeacher = $this->teacher();
        $expiresAt = now()->addDays(7);

        foreach ($approvers as $approver) {
            if ($approver->user->email === null) {
                continue;
            }

            $approveUrl = UrlFacade::temporarySignedRoute(
                'student-claim.approve',
                $expiresAt,
                ['student' => $student->id, 'teacher' => $requestingTeacher->id, 'school' => $school->id],
            );
            $denyUrl = UrlFacade::temporarySignedRoute(
                'student-claim.deny',
                $expiresAt,
                ['student' => $student->id, 'teacher' => $requestingTeacher->id, 'school' => $school->id],
            );

            Mail::to($approver->user->email)->send(new StudentClaimMail($requestingTeacher, $student, $school, $approveUrl, $denyUrl, $expiresAt));
        }
    }

    /**
     * @return list<array{grade: int, label: string}>
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

        return $this->gradeOptionsForSchool($school);
    }

    /**
     * @return list<array{grade: int, label: string}>
     */
    public function editGradeOptions(): array
    {
        if ($this->editingRowId === null) {
            return [];
        }

        $row = $this->teacherRow($this->editingRowId)->with('school')->first();

        if ($row === null) {
            return [];
        }

        return $this->gradeOptionsForSchool($row->school);
    }

    public function edit(int $rowId): void
    {
        $row = $this->teacherRow($rowId)->with(['student.user', 'student.emergencyContacts', 'student.homeSchool', 'school'])->firstOrFail();

        // A pending cross-org claim hasn't been approved by the student's
        // existing teacher(s) yet — loading the full profile here would hand
        // over emergency contacts, address, and birthday before that approval,
        // defeating the whole point of the claim workflow. The Edit trigger is
        // also disabled in the blade for a pending row; this is the backstop.
        if ($row->isPending()) {
            Flux::toast(text: 'This request is still pending approval — nothing to edit yet.', variant: 'warning');

            return;
        }

        $student = $row->student;
        $user = $student->user;
        $homeAddress = HomeAddress::where('student_id', $student->id)->first();
        $schoolStudent = SchoolStudent::where('student_id', $row->student_id)->where('school_id', $row->school_id)->first();

        $this->editingRowId = $row->id;
        $this->editingSchoolName = $row->school->name;
        $this->editingSchoolIsStudio = $row->school->getRawOriginal('type') === SchoolType::Studio->value;
        $this->dismissedStudentMatchIds = [];
        $this->resetAttachingStudent();
        $this->resetStudentClaim();
        $this->edit_grade = $schoolStudent !== null
            ? (string) ClassOfCalculator::gradeFromClassOf((int) $schoolStudent->class_of, $row->school->senior_year)
            : '';
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

        $this->resetHomeSchoolFields();
        $homeSchool = $student->homeSchool;
        $this->edit_home_school_name = $homeSchool !== null ? $homeSchool->name : '';
        $this->edit_home_school_id = $student->home_school_id !== null ? (string) $student->home_school_id : '';

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

    /**
     * Existing schools/studios that look like what's being typed into the
     * Student's School field, scoped to type=school (a studio doesn't track
     * a "home school" of its own) and only while the search is still unresolved.
     *
     * @return Collection<int, array{school: School, percent: float}>
     */
    public function homeSchoolSuggestions(): Collection
    {
        if ($this->edit_home_school_id !== '' || trim($this->edit_home_school_name) === '') {
            return collect();
        }

        return SchoolMatcher::suggestions(
            $this->edit_home_school_name,
            $this->edit_home_school_geostate_id !== '' ? (int) $this->edit_home_school_geostate_id : null,
            $this->edit_home_school_zip_code !== '' ? $this->edit_home_school_zip_code : null,
            $this->edit_home_school_county_id !== '' ? (int) $this->edit_home_school_county_id : null,
            SchoolType::School,
        );
    }

    public function selectHomeSchool(int $schoolId): void
    {
        $school = School::findOrFail($schoolId);

        $this->edit_home_school_id = (string) $school->id;
        $this->edit_home_school_name = $school->name;
        $this->edit_home_school_confirmed_new = false;
    }

    public function confirmNewHomeSchool(): void
    {
        $this->edit_home_school_confirmed_new = true;
    }

    public function cancelNewHomeSchool(): void
    {
        $this->edit_home_school_confirmed_new = false;
        $this->edit_home_school_city = '';
        $this->edit_home_school_zip_code = '';
        $this->edit_home_school_geostate_id = '';
        $this->edit_home_school_county_id = '';
    }

    /**
     * Clears the resolved/matched home school so the search field reopens —
     * the only way to change it once matched, since retyping over a matched
     * name wouldn't otherwise un-resolve the selection.
     */
    public function changeHomeSchool(): void
    {
        $this->edit_home_school_id = '';
        $this->edit_home_school_name = '';
        $this->edit_home_school_confirmed_new = false;
    }

    public function updatedEditHomeSchoolGeostateId(): void
    {
        $this->edit_home_school_county_id = '';
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
        $row = $this->teacherRow($this->editingRowId)->with(['student.user', 'school'])->firstOrFail();
        $student = $row->student;
        $user = $student->user;

        $this->stripPhoneFormatting();

        $homeAddressProvided = $this->edit_home_address1 !== ''
            || $this->edit_home_city !== ''
            || $this->edit_home_geo_state !== ''
            || $this->edit_home_zip_code !== '';

        $this->validate([
            'edit_grade' => ['required', 'integer', 'min:4', 'max:12'],
            ...$this->profileValidationRules($user->id, $homeAddressProvided),
        ], $this->profileValidationMessages());

        $homeSchoolId = $this->resolveHomeSchoolId();

        if ($this->isStudioContext() && $homeSchoolId === null) {
            return;
        }

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
            'home_school_id' => $homeSchoolId,
        ]);

        SchoolStudent::where('student_id', $row->student_id)
            ->where('school_id', $row->school_id)
            ->update(['class_of' => ClassOfCalculator::classOfFromGrade((int) $this->edit_grade, $row->school->senior_year)]);

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
        if ($this->blockingStudentMatches()->isNotEmpty()) {
            $this->addError('edit_first_name', 'Resolve the possible matching student(s) above before adding a new one.');

            return;
        }

        $this->stripPhoneFormatting();

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
        ], $this->profileValidationMessages());

        $school = School::findOrFail((int) $this->add_school_id);

        $homeSchoolId = $this->resolveHomeSchoolId();

        if ($this->isStudioContext() && $homeSchoolId === null) {
            return;
        }

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
            'home_school_id' => $homeSchoolId,
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
            'geostates' => Geostate::orderBy('name')->get(),
            'homeSchoolCounties' => $this->edit_home_school_geostate_id !== ''
                ? County::where('geostate_id', $this->edit_home_school_geostate_id)->orderBy('name')->get()
                : collect(),
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
     * The cell/home/work phone inputs display a "(999) 999-9999" mask (see
     * mask:dynamic in the blade), but the mask plugin writes its formatted
     * text straight into the bound Livewire property — strip it back to
     * digits-only before validating or saving, matching the convention used
     * by Login, Profile, and TeacherRegister.
     */
    private function stripPhoneFormatting(): void
    {
        $this->edit_cell_phone = preg_replace('/\D/', '', $this->edit_cell_phone);

        foreach ($this->edit_emergency_contacts as $index => $contact) {
            $this->edit_emergency_contacts[$index]['cell_phone'] = preg_replace('/\D/', '', $contact['cell_phone']);
            $this->edit_emergency_contacts[$index]['home_phone'] = preg_replace('/\D/', '', $contact['home_phone']);
            $this->edit_emergency_contacts[$index]['work_phone'] = preg_replace('/\D/', '', $contact['work_phone']);
        }
    }

    private function resetHomeSchoolFields(): void
    {
        $this->edit_home_school_name = '';
        $this->edit_home_school_id = '';
        $this->edit_home_school_confirmed_new = false;
        $this->edit_home_school_city = '';
        $this->edit_home_school_zip_code = '';
        $this->edit_home_school_geostate_id = '';
        $this->edit_home_school_county_id = '';
    }

    private function resetAttachingStudent(): void
    {
        $this->attachingStudentId = null;
        $this->attachingStudentName = '';
        $this->attachingStudentSchoolName = '';
        $this->attachingStudentGrade = null;
    }

    private function resetStudentClaim(): void
    {
        $this->claimingStudentId = null;
        $this->claimingStudentName = '';
        $this->claimingStudentSchoolName = '';
        $this->claim_grade = '';
        $this->claimWillAutoApprove = false;
    }

    /**
     * Unresolved matches that must be addressed before saveAdd() can proceed.
     * A strong match (effectively certain — same birthday and name) blocks
     * regardless of context. A weak match (name similarity alone) only blocks
     * in a studio, which is far more likely to be re-adding a student who
     * already has a school record elsewhere than a school is.
     *
     * @return Collection<int, array{student: Student, tier: string}>
     */
    private function blockingStudentMatches(): Collection
    {
        $unresolved = $this->unresolvedStudentMatches();

        return $this->isStudioContext()
            ? $unresolved
            : $unresolved->filter(fn (array $match) => $match['tier'] === 'strong');
    }

    /**
     * Resolves the student's home school id for saveAdd()/saveEdit() — null when
     * the row's school isn't a studio (nothing to record), the previously matched
     * school's id when one was picked from the suggestions, or a newly created
     * School (type=school) when the teacher confirmed a new one. Required when
     * isStudioContext(): adds a field error and returns null if neither a match
     * nor a confirmed new school has been provided yet.
     */
    private function resolveHomeSchoolId(): ?int
    {
        if (! $this->isStudioContext()) {
            return null;
        }

        if ($this->edit_home_school_id !== '') {
            return (int) $this->edit_home_school_id;
        }

        if (! $this->edit_home_school_confirmed_new) {
            $this->addError('edit_home_school_name', "Select the student's school, or add it as a new school, before saving.");

            return null;
        }

        $this->validate([
            'edit_home_school_name' => ['required', 'string', 'max:255'],
            'edit_home_school_city' => ['required', 'string', 'max:255'],
            'edit_home_school_zip_code' => ['required', 'string', 'max:5'],
            'edit_home_school_geostate_id' => ['nullable', 'integer', Rule::exists(Geostate::class, 'id')],
            'edit_home_school_county_id' => ['required', 'integer', Rule::exists(County::class, 'id')],
        ]);

        $school = School::firstOrCreate(
            ['name' => $this->edit_home_school_name, 'zip_code' => $this->edit_home_school_zip_code],
            [
                'type' => SchoolType::School->value,
                'city' => $this->edit_home_school_city,
                'geostate_id' => $this->edit_home_school_geostate_id !== '' ? (int) $this->edit_home_school_geostate_id : null,
                'county_id' => (int) $this->edit_home_school_county_id,
                'school_year' => 'US',
            ]
        );

        return $school->id;
    }

    /**
     * @return list<array{grade: int, label: string}>
     */
    private function gradeOptionsForSchool(School $school): array
    {
        return array_map(
            fn (int $grade) => [
                'grade' => $grade,
                'label' => "{$grade}th Grade (Class of ".ClassOfCalculator::classOfFromGrade($grade, $school->senior_year).')',
            ],
            range(12, 4),
        );
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

    /**
     * Shared by saveEdit() and saveAdd() alongside profileValidationRules() —
     * :position is a Laravel placeholder that resolves to the contact's
     * 1-based position in edit_emergency_contacts, matching the "Contact N"
     * heading shown above each row in the form.
     *
     * @return array<string, string>
     */
    private function profileValidationMessages(): array
    {
        return [
            'edit_birthday.before_or_equal' => 'The student must be at least 9 years old.',
            'edit_birthday.after' => 'The student must be no older than 19.',
            'edit_first_name.regex' => 'First name may only contain letters, spaces, hyphens, and apostrophes.',
            'edit_middle_name.regex' => 'Middle name may only contain letters, spaces, hyphens, and apostrophes.',
            'edit_last_name.regex' => 'Last name may only contain letters, spaces, hyphens, and apostrophes.',

            'edit_emergency_contacts.*.name.required' => 'Contact :position needs a name.',
            'edit_emergency_contacts.*.relationship.required' => 'Contact :position needs a relationship.',
            'edit_emergency_contacts.*.email.required' => 'Contact :position needs an email address.',
            'edit_emergency_contacts.*.email.email' => 'Contact :position needs a valid email address.',
            'edit_emergency_contacts.*.cell_phone.required' => 'Contact :position needs a cell phone number.',
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
                EmergencyContact::where('id', $contact['id'])->where('student_id', $student->id)->first()?->update($attributes);
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
     * Subjects this teacher teaches at the currently-selected add school specifically,
     * used to default the Subject field in the claim modal.
     *
     * @return list<string>
     */
    private function subjectsForAddSchool(): array
    {
        if ($this->add_school_id === '') {
            return [];
        }

        $schoolTeacher = SchoolTeacher::where('teacher_id', $this->teacher()->id)
            ->where('school_id', (int) $this->add_school_id)
            ->where('is_active', true)
            ->first();

        if ($schoolTeacher === null) {
            return [];
        }

        return SchoolTeacherSubject::where('school_teacher_id', $schoolTeacher->id)
            ->get()
            ->map(fn (SchoolTeacherSubject $row) => $row->getRawOriginal('subject'))
            ->values()
            ->all();
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
            ->whereNotNull('school_teacher.verified_at')
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
