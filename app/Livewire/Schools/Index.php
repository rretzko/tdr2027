<?php

declare(strict_types=1);

namespace App\Livewire\Schools;

use App\Enums\SchoolType;
use App\Enums\TeacherRole;
use App\Mail\SchoolEmailVerificationMail;
use App\Models\CoTeacherGrant;
use App\Models\County;
use App\Models\Geostate;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\School;
use App\Models\Teacher;
use App\Rules\NotCommercialEmailDomain;
use App\Services\CoTeacherAccessService;
use App\Support\CommercialEmailDomains;
use App\Support\SchoolMatcher;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public bool $isAdding = false;

    public bool $confirmedNewSchool = false;

    /**
     * Set once the teacher picks a suggested existing school instead of creating
     * a new one. Only a selection — saving still waits for the Save button, which
     * calls linkExistingSchool() against this id instead of saveAdd().
     */
    public ?int $linkingSchoolId = null;

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

    /**
     * Which school's Co-Teachers modal is open — see
     * docs/plans/co-teacher-definition.md §5.
     */
    public ?int $managingCoTeachersSchoolId = null;

    public string $newCoTeacherId = '';

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
        // A model-instance update, not updateExistingPivot() (a raw query-
        // builder statement that never fires Eloquent events) — SchoolTeacher
        // is #[ObservedBy(SchoolTeacherObserver::class)], which auto-revokes
        // any co_teacher_grants row resting on this (school, teacher) pair
        // going inactive (docs/plans/co-teacher-definition.md §3).
        $this->schoolTeacherPivot($schoolId)?->update([
            'is_active' => false,
            'school_email' => null,
            'verified_at' => null,
        ]);
    }

    public function activate(int $schoolId): void
    {
        $this->schoolTeacherPivot($schoolId)?->update(['is_active' => true]);
    }

    private function schoolTeacherPivot(int $schoolId): ?SchoolTeacher
    {
        return SchoolTeacher::where('teacher_id', $this->teacher()->id)
            ->where('school_id', $schoolId)
            ->first();
    }

    public function isPending(SchoolTeacher $pivot): bool
    {
        return $pivot->isPending();
    }

    public function remove(int $schoolId): void
    {
        $teacher = $this->teacher();

        if (StudentTeacher::where('teacher_id', $teacher->id)->where('school_id', $schoolId)->exists()) {
            $this->addError('remove', 'This school cannot be removed while students are linked to you there.');

            return;
        }

        // school_teacher_subject rows (the subjects taught there) foreign-key to
        // school_teacher and aren't cascade-deleted, so they have to go first or
        // detach() below fails with an integrity constraint violation.
        SchoolTeacher::where('teacher_id', $teacher->id)->where('school_id', $schoolId)->first()?->subjects()->delete();

        $teacher->schools()->detach($schoolId);
    }

    public function add(): void
    {
        $this->editingSchoolId = null;
        $this->isAdding = true;
        $this->confirmedNewSchool = false;
        $this->linkingSchoolId = null;

        $this->edit_role = TeacherRole::Primary->value;
        $this->edit_is_replacing_teacher = false;
        $this->edit_replacing_teacher_name = '';
        $this->edit_school_email = '';

        $this->edit_name = '';
        $this->edit_type = SchoolType::School->value;
        $this->edit_city = '';
        $this->edit_zip_code = '';
        $this->edit_geostate_id = '';
        $this->edit_county_id = '';

        $this->resetErrorBag();
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

    /**
     * Other teachers linked to the school in scope (active or not — a departing
     * teacher's pivot row isn't necessarily removed when they leave), so the
     * teacher being replaced can be picked from a list instead of free-typed and
     * risk a typo that breaks the record's usefulness. Empty while adding a
     * brand-new school, since there's no one there yet to replace.
     *
     * @return Collection<int, string>
     */
    public function replacingTeacherOptions(?int $schoolId = null): Collection
    {
        $schoolId ??= $this->linkingSchoolId ?? $this->editingSchoolId;

        if ($schoolId === null) {
            return collect();
        }

        return Teacher::query()
            ->whereHas('schools', fn ($query) => $query->where('schools.id', $schoolId))
            ->where('id', '!=', $this->teacher()->id)
            ->with('user')
            ->get()
            ->pluck('user.name')
            ->filter(fn (?string $name): bool => filled($name))
            ->unique()
            ->sort()
            ->values();
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

    /**
     * Existing schools that look like what's being typed into the Add-school form,
     * so a teacher is steered toward linking to one of them instead of creating a
     * duplicate. Only relevant while adding — editing a school against itself would
     * just match the record being edited.
     *
     * @return Collection<int, array{school: School, percent: float}>
     */
    public function schoolSuggestions(): Collection
    {
        if (! $this->isAdding) {
            return collect();
        }

        return SchoolMatcher::suggestions(
            $this->edit_name,
            $this->edit_geostate_id !== '' ? (int) $this->edit_geostate_id : null,
            $this->edit_zip_code !== '' ? $this->edit_zip_code : null,
            $this->edit_county_id !== '' ? (int) $this->edit_county_id : null,
        );
    }

    /**
     * Marks a suggested school as the one the teacher means, without saving
     * anything yet — only the Save button persists it (via linkExistingSchool()).
     */
    public function selectSuggestedSchool(int $schoolId): void
    {
        $school = School::findOrFail($schoolId);

        $this->linkingSchoolId = $schoolId;
        $this->confirmedNewSchool = false;

        $this->edit_type = (string) $school->getRawOriginal('type');
        $this->edit_city = $school->city;
        $this->edit_zip_code = $school->zip_code;
        $this->edit_geostate_id = $school->geostate_id !== null ? (string) $school->geostate_id : '';
        $this->edit_county_id = (string) $school->county_id;
    }

    /**
     * Confirms the teacher wants to create a new school rather than link to one
     * of the suggested matches.
     */
    public function confirmNewSchool(): void
    {
        $this->confirmedNewSchool = true;
        $this->linkingSchoolId = null;
    }

    /**
     * Links the teacher to an existing school suggested as a likely duplicate,
     * instead of creating a new School record for it.
     */
    public function linkExistingSchool(int $schoolId): void
    {
        $teacher = $this->teacher();
        $school = School::findOrFail($schoolId);

        $this->validate($this->teacherRelationshipValidationRules());

        $existingPivot = SchoolTeacher::where('school_id', $school->id)->where('teacher_id', $teacher->id)->first();
        $newSchoolEmail = $this->edit_school_email !== '' ? $this->edit_school_email : null;
        $previousSchoolEmail = $existingPivot !== null && $existingPivot->school_email !== '' ? $existingPivot->school_email : null;

        SchoolTeacher::updateOrCreate(
            ['school_id' => $school->id, 'teacher_id' => $teacher->id],
            [
                'role' => $this->edit_role,
                'replacing_teacher_name' => $this->edit_is_replacing_teacher ? $this->edit_replacing_teacher_name : null,
                'is_active' => true,
                'school_email' => $newSchoolEmail,
                ...$this->schoolEmailVerificationFields($previousSchoolEmail, $newSchoolEmail, $teacher),
            ]
        );

        if ($newSchoolEmail !== null && $newSchoolEmail !== $previousSchoolEmail) {
            $this->sendVerificationEmailIfNeeded($school->id, $teacher);
        }

        $this->isAdding = false;
        $this->confirmedNewSchool = false;
        $this->linkingSchoolId = null;
        $this->modal('edit-school')->close();

        Flux::toast(text: $this->addedSchoolToastMessage("You're now linked to {$school->name}."), variant: 'success');
    }

    public function saveEdit(): void
    {
        $teacher = $this->teacher();
        $school = $teacher->schools()->findOrFail($this->editingSchoolId);
        $pivot = $school->pivot;

        $this->validate($this->schoolValidationRules($school->id));

        $newSchoolEmail = $this->edit_school_email !== '' ? $this->edit_school_email : null;
        // Normalized in case school_email was left as '' rather than NULL by a direct
        // database edit — otherwise that's indistinguishable from a real change below.
        $previousSchoolEmail = $pivot->school_email !== '' ? $pivot->school_email : null;

        // $pivot->update(), not updateExistingPivot() — see the comment on
        // deactivate() above; $pivot is already the real SchoolTeacher model
        // instance for this row (Teacher::schools() uses ->using(SchoolTeacher::class)).
        $pivot->update([
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

    public function saveAdd(): void
    {
        $teacher = $this->teacher();

        $this->validate($this->schoolValidationRules(null));

        // Re-checked server-side (not just gated behind the UI's "None of these"
        // button) so a duplicate can't be created by resubmitting after suggestions
        // were dismissed, or by calling this method directly.
        if (! $this->confirmedNewSchool && $this->schoolSuggestions()->isNotEmpty()) {
            $this->addError('edit_name', 'This looks like it may already exist — pick one of the suggested matches above, or confirm you want to add a new one.');

            return;
        }

        $school = School::create([
            'name' => $this->edit_name,
            'type' => $this->edit_type,
            'city' => $this->edit_city,
            'zip_code' => $this->edit_zip_code,
            'geostate_id' => $this->edit_geostate_id !== '' ? (int) $this->edit_geostate_id : null,
            'county_id' => (int) $this->edit_county_id,
            'school_year' => 'US',
        ]);

        $newSchoolEmail = $this->edit_school_email !== '' ? $this->edit_school_email : null;

        $teacher->schools()->attach($school->id, [
            'role' => $this->edit_role,
            'replacing_teacher_name' => $this->edit_is_replacing_teacher ? $this->edit_replacing_teacher_name : null,
            'is_active' => true,
            'school_email' => $newSchoolEmail,
            ...$this->schoolEmailVerificationFields(null, $newSchoolEmail, $teacher),
        ]);

        if ($newSchoolEmail !== null) {
            $this->sendVerificationEmailIfNeeded($school->id, $teacher);
        }

        $this->isAdding = false;
        $this->modal('edit-school')->close();

        Flux::toast(text: $this->addedSchoolToastMessage("{$school->name} added successfully."), variant: 'success');
    }

    /**
     * Appends a note about the pending student transfer to a just-added-school
     * toast, when a replacement teacher was identified — the transfer itself only
     * runs once the school email is verified (see ReplacedTeacherStudentTransfer).
     */
    private function addedSchoolToastMessage(string $message): string
    {
        if (! $this->edit_is_replacing_teacher) {
            return $message;
        }

        return "{$message} Current students will be transferred to you once your school email is verified.";
    }

    /**
     * Validation rules shared by saveEdit() and saveAdd() — identical for both;
     * only whether an existing school's own name is excluded from the uniqueness
     * check differs (a new school has no id yet to exclude).
     *
     * @return array<string, array<int, mixed>>
     */
    private function schoolValidationRules(?int $ignoreSchoolId): array
    {
        return [
            ...$this->teacherRelationshipValidationRules(),
            'edit_name' => [
                'required', 'string', 'max:255',
                Rule::unique('schools', 'name')->where('zip_code', $this->edit_zip_code)->ignore($ignoreSchoolId),
            ],
            'edit_type' => ['required', Rule::in([SchoolType::School->value, SchoolType::Studio->value])],
            'edit_city' => ['required', 'string', 'max:255'],
            'edit_zip_code' => ['required', 'string', 'max:5'],
            'edit_geostate_id' => ['nullable', 'integer', Rule::exists(Geostate::class, 'id')],
            'edit_county_id' => ['required', 'integer', Rule::exists(County::class, 'id')],
        ];
    }

    /**
     * The subset of validation rules covering only the teacher's relationship to a
     * school (role, replacing-teacher note, school email) — used both as part of
     * schoolValidationRules() and on its own by linkExistingSchool(), which doesn't
     * touch the school's own fields.
     *
     * @return array<string, array<int, mixed>>
     */
    private function teacherRelationshipValidationRules(): array
    {
        $schoolEmailRules = ['required', 'email', 'max:255'];

        if ($this->edit_school_email !== '') {
            $schoolEmailRules[] = new NotCommercialEmailDomain;
        }

        return [
            'edit_role' => ['required', Rule::in([TeacherRole::Primary->value, TeacherRole::Coteacher->value])],
            'edit_replacing_teacher_name' => [$this->edit_is_replacing_teacher ? 'required' : 'nullable', 'string', 'max:255'],
            'edit_school_email' => $schoolEmailRules,
        ];
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
        // Re-fetched rather than threading a $pivot instance through from
        // either caller — both linkExistingSchool() and saveEdit() already
        // have their own for a different purpose, but re-querying here keeps
        // this method self-contained regardless of caller.
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

    /**
     * Opens the Co-Teachers modal for one of this teacher's own
     * active+verified schools — docs/plans/co-teacher-definition.md §5.
     */
    public function manageCoTeachers(int $schoolId): void
    {
        $pivot = $this->schoolTeacherPivot($schoolId);

        abort_if($pivot === null || ! $pivot->is_active || $pivot->verified_at === null, 403);

        $this->managingCoTeachersSchoolId = $schoolId;
        $this->newCoTeacherId = '';
        $this->resetErrorBag();
        $this->modal('co-teachers')->show();
    }

    /**
     * Unilateral, immediate — no approval from the recipient
     * (docs/plans/co-teacher-definition.md §0).
     */
    public function grantCoTeacher(CoTeacherAccessService $coTeacherAccess): void
    {
        abort_if($this->managingCoTeachersSchoolId === null, 400);

        $teacher = $this->teacher();
        $school = $this->schoolTeacherPivot($this->managingCoTeachersSchoolId)?->school;

        abort_if($school === null, 404);

        $grantableIds = $coTeacherAccess->grantableTeachers($teacher, $school)->pluck('id')->all();

        $this->validate([
            'newCoTeacherId' => ['required', Rule::in($grantableIds)],
        ]);

        CoTeacherGrant::create([
            'school_id' => $school->id,
            'granting_teacher_id' => $teacher->id,
            'co_teacher_id' => (int) $this->newCoTeacherId,
            'granted_by_user_id' => Auth::id(),
        ]);

        $this->newCoTeacherId = '';

        Flux::toast('Co-teacher access granted.', variant: 'success');
    }

    /**
     * Only the teacher who granted a share can revoke it — not the
     * recipient, and not "any manager" (docs/plans/co-teacher-definition.md §6).
     */
    public function revokeCoTeacherGrant(int $grantId): void
    {
        $grant = CoTeacherGrant::where('id', $grantId)
            ->where('granting_teacher_id', $this->teacher()->id)
            ->firstOrFail();

        $grant->delete();

        Flux::toast('Co-teacher access revoked.');
    }

    public function render(CoTeacherAccessService $coTeacherAccess): View
    {
        $teacher = $this->teacher();
        $managingSchool = $this->managingCoTeachersSchoolId !== null
            ? School::find($this->managingCoTeachersSchoolId)
            : null;

        return view('livewire.schools.index', [
            'schools' => $this->schools(),
            'geostates' => Geostate::orderBy('name')->get(),
            'editCounties' => $this->edit_geostate_id !== ''
                ? County::where('geostate_id', $this->edit_geostate_id)->orderBy('name')->get()
                : collect(),
            'managingSchool' => $managingSchool,
            'coTeacherGrantsGiven' => $managingSchool !== null
                ? CoTeacherGrant::where('school_id', $managingSchool->id)->where('granting_teacher_id', $teacher->id)->with('coTeacher.user')->get()
                : collect(),
            'coTeacherGrantsReceived' => $managingSchool !== null
                ? CoTeacherGrant::where('school_id', $managingSchool->id)->where('co_teacher_id', $teacher->id)->with('grantingTeacher.user')->get()
                : collect(),
            'grantableCoTeachers' => $managingSchool !== null
                ? $coTeacherAccess->grantableTeachers($teacher, $managingSchool)
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
