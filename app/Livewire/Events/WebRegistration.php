<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Services\TeacherStudentTransferService;
use App\Services\VersionInvitationEligibilityService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Web Registration Manager Module (event-version-orientation.md §5.11):
 * lets a Web Registration Manager (or Founder/Event Manager) impersonate a
 * teacher invited to this Version, and transfer students between invited
 * teachers/schools.
 */
#[Layout('components.layouts.app')]
class WebRegistration extends Component
{
    public Version $version;

    public string $activeTab = 'impersonate';

    // Impersonate Teacher
    public string $impersonateSearch = '';

    // Transfer Students
    public ?int $fromSchoolId = null;

    public ?int $fromTeacherId = null;

    public ?int $toSchoolId = null;

    public ?int $toTeacherId = null;

    /** @var list<int> */
    public array $selectedStudentIds = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageWebRegistration(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function updatedFromSchoolId(): void
    {
        $this->fromTeacherId = null;

        $teachers = $this->teachersAtSchool($this->fromSchoolId);

        // A school with only one invited teacher has nothing to choose — skip
        // straight to that teacher's roster instead of making the manager
        // pick from a single-option dropdown.
        if ($teachers->count() === 1) {
            $this->fromTeacherId = $teachers->first()->id;
        }

        $this->syncSelectedFromStudents();
        $this->resetToSelectionIfFromIncomplete();
    }

    public function updatedFromTeacherId(): void
    {
        $this->syncSelectedFromStudents();
        $this->resetToSelectionIfFromIncomplete();

        // The From teacher can never be a valid To selection — if changing
        // From just made an already-picked To teacher collide with it, drop
        // the now-invalid To teacher rather than leaving a stale selection
        // the teacher list no longer even offers.
        if ($this->toTeacherId !== null && $this->toTeacherId === $this->fromTeacherId) {
            $this->toTeacherId = null;
        }
    }

    public function updatedSelectedStudentIds(): void
    {
        $this->resetToSelectionIfFromIncomplete();
    }

    public function updatedToSchoolId(): void
    {
        $this->toTeacherId = null;

        $teachers = $this->toTeacherOptions();

        // Same reasoning as updatedFromSchoolId() — a school with only one
        // selectable teacher has nothing to choose, so skip straight to
        // their roster instead of leaving a single-option dropdown for the
        // manager to click through.
        if ($teachers->count() === 1) {
            $this->toTeacherId = $teachers->first()->id;
        }
    }

    /**
     * "Transfer To" only makes sense once From has a school, a teacher, and
     * at least one checked student — until then it's disabled in the view
     * and any stale To selection is cleared here so it can't linger behind
     * a disabled control.
     */
    private function resetToSelectionIfFromIncomplete(): void
    {
        if (! $this->fromIsComplete()) {
            $this->toSchoolId = null;
            $this->toTeacherId = null;
        }
    }

    private function fromIsComplete(): bool
    {
        return $this->fromSchoolId !== null
            && $this->fromTeacherId !== null
            && $this->selectedStudentIds !== [];
    }

    /**
     * Every current student of the (auto- or manually-)selected From
     * teacher/school starts checked — the manager unchecks the ones they
     * don't want to move, rather than having to check each one individually.
     */
    private function syncSelectedFromStudents(): void
    {
        if ($this->fromSchoolId === null || $this->fromTeacherId === null) {
            $this->selectedStudentIds = [];

            return;
        }

        $school = School::find($this->fromSchoolId);
        $teacher = Teacher::find($this->fromTeacherId);

        if ($school === null || $teacher === null) {
            $this->selectedStudentIds = [];

            return;
        }

        $this->selectedStudentIds = app(TeacherStudentTransferService::class)
            ->currentStudents($school, $teacher)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * No school selected yet means the (disabled) Teacher dropdown has
     * nothing to show — not the full invited-teacher pool, which for a
     * large Version can run into the hundreds.
     *
     * @return Collection<int, Teacher>
     */
    private function teachersAtSchool(?int $schoolId): Collection
    {
        if ($schoolId === null) {
            return new Collection;
        }

        return app(VersionInvitationEligibilityService::class)->invitedTeachers($this->version)
            ->filter(fn (Teacher $teacher): bool => $teacher->schools->contains('id', $schoolId))
            ->values();
    }

    /**
     * The To teacher list is the same "invited teachers at this school" pool
     * as From, minus the From teacher when the same school is picked on both
     * sides — picking the same teacher as both source and destination is a
     * no-op transfer to nowhere, so it's never offered as a To choice.
     *
     * @return Collection<int, Teacher>
     */
    private function toTeacherOptions(): Collection
    {
        $teachers = $this->teachersAtSchool($this->toSchoolId);

        if ($this->toSchoolId === null || $this->toSchoolId !== $this->fromSchoolId) {
            return $teachers;
        }

        return $teachers->reject(fn (Teacher $teacher): bool => $teacher->id === $this->fromTeacherId)->values();
    }

    /**
     * @return Collection<int, Teacher>
     */
    public function teachersForImpersonation(VersionInvitationEligibilityService $eligibility): Collection
    {
        $search = trim($this->impersonateSearch);

        if ($search === '') {
            return new Collection;
        }

        return $eligibility->invitedTeachers($this->version)
            ->filter(fn (Teacher $teacher): bool => str_contains(mb_strtolower($teacher->user->name), mb_strtolower($search)))
            ->values();
    }

    public function impersonate(int $userId, VersionRoleAssignmentService $roles, VersionInvitationEligibilityService $eligibility): void
    {
        abort_unless($roles->canManageWebRegistration(Auth::user(), $this->version), 403);

        $target = User::query()->whereHas('teacher')->findOrFail($userId);

        $invitedTeacherIds = $eligibility->invitedTeachers($this->version)->pluck('id');
        abort_unless($invitedTeacherIds->contains($target->teacher->id), 403);

        session()->put('impersonator_id', Auth::id());
        session()->put('impersonation_scope', 'web_registration_manager');
        session()->put('impersonation_version_id', $this->version->id);

        Auth::login($target);

        $this->redirect(route('registrations.version', $this->version), navigate: true);
    }

    public function transferStudents(TeacherStudentTransferService $service, VersionRoleAssignmentService $roles, VersionInvitationEligibilityService $eligibility): void
    {
        abort_unless($roles->canManageWebRegistration(Auth::user(), $this->version), 403);

        $validated = $this->validate([
            'fromSchoolId' => ['required', 'integer', 'exists:schools,id'],
            'fromTeacherId' => ['required', 'integer', 'exists:teachers,id'],
            'toSchoolId' => ['required', 'integer', 'exists:schools,id'],
            'toTeacherId' => ['required', 'integer', 'exists:teachers,id', 'different:fromTeacherId'],
            'selectedStudentIds' => ['required', 'array', 'min:1'],
            'selectedStudentIds.*' => ['integer'],
        ]);

        $invitedTeacherIds = $eligibility->invitedTeachers($this->version)->pluck('id');
        abort_unless($invitedTeacherIds->contains((int) $validated['fromTeacherId']), 403);
        abort_unless($invitedTeacherIds->contains((int) $validated['toTeacherId']), 403);

        $fromSchool = School::findOrFail($validated['fromSchoolId']);
        $fromTeacher = Teacher::findOrFail($validated['fromTeacherId']);
        $toSchool = School::findOrFail($validated['toSchoolId']);
        $toTeacher = Teacher::findOrFail($validated['toTeacherId']);

        $count = $service->transfer($fromSchool, $fromTeacher, $toSchool, $toTeacher, $validated['selectedStudentIds']);

        $this->selectedStudentIds = [];

        Flux::toast(text: "{$count} student(s) transferred to {$toTeacher->user->name}.", variant: 'success');
    }

    public function render(TeacherStudentTransferService $transferService, VersionInvitationEligibilityService $eligibility): View
    {
        $invitedTeachers = $eligibility->invitedTeachers($this->version);

        $schoolOptions = $invitedTeachers->flatMap(fn (Teacher $teacher): Collection => $teacher->schools)
            ->unique('id')
            ->sortBy('name')
            ->values();

        $fromTeacherOptions = $this->teachersAtSchool($this->fromSchoolId);
        $toTeacherOptions = $this->toTeacherOptions();

        $fromStudents = ($this->fromSchoolId !== null && $this->fromTeacherId !== null)
            ? $transferService->currentStudents(School::findOrFail($this->fromSchoolId), Teacher::findOrFail($this->fromTeacherId))
            : new Collection;

        $toStudents = ($this->toSchoolId !== null && $this->toTeacherId !== null)
            ? $transferService->currentStudents(School::findOrFail($this->toSchoolId), Teacher::findOrFail($this->toTeacherId))
            : new Collection;

        return view('livewire.events.web-registration', [
            'teachersForImpersonation' => $this->teachersForImpersonation($eligibility),
            'schoolOptions' => $schoolOptions,
            'fromTeacherOptions' => $fromTeacherOptions,
            'toTeacherOptions' => $toTeacherOptions,
            'fromStudents' => $fromStudents,
            'toStudents' => $toStudents,
            'fromComplete' => $this->fromIsComplete(),
        ]);
    }
}
