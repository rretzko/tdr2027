<?php

declare(strict_types=1);

namespace App\Livewire\Sfdi;

use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Models\Geostate;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\StudentTeacher;
use App\Models\School as SchoolModel;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\ClassOfCalculator;
use App\Support\SchoolMatcher;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Student self-service school + teacher join, per studentfolder-module.md
 * §4.2 — existing schools/teachers only (no create-school path, unlike the
 * teacher onboarding wizard this mirrors), direct join with no approval
 * step. Also serves as the mandatory first-run destination
 * (EnsureStudentHasActiveSchool) and the ongoing "switch schools" page.
 */
#[Layout('components.layouts.app')]
class School extends Component
{
    /** This app's supported grade range (project_overview.md: "students grades 4-12") — not derived from School::grades()/SchoolGrade rows, which may be unconfigured for a given school. */
    private const GRADES = [4, 5, 6, 7, 8, 9, 10, 11, 12];

    public string $geostate_id = '';

    public string $zip_code = '';

    public string $school_search = '';

    public ?int $selected_school_id = null;

    public string $grade = '';

    /** @var array<int, list<string>> teacher_id => selected Subject values */
    public array $teacherSubjects = [];

    public function mount(): void
    {
        abort_if($this->student() === null, 403);

        if ($this->geostate_id === '') {
            $newJersey = Geostate::where('name', 'New Jersey')->first();
            $this->geostate_id = $newJersey !== null ? (string) $newJersey->id : '';
        }
    }

    public function selectSchool(int $schoolId): void
    {
        $this->selected_school_id = $schoolId;
        $this->grade = '';
        $this->teacherSubjects = [];
    }

    public function changeSchool(): void
    {
        $this->selected_school_id = null;
        $this->grade = '';
        $this->teacherSubjects = [];
    }

    public function join(): void
    {
        abort_if($this->selected_school_id === null, 422);

        $school = SchoolModel::findOrFail($this->selected_school_id);

        $this->validate([
            'grade' => ['required', 'integer', Rule::in(self::GRADES)],
        ]);

        $selectedTeacherIds = collect($this->teacherSubjects)
            ->filter(fn (array $subjects): bool => $subjects !== [])
            ->keys();

        if ($selectedTeacherIds->isEmpty()) {
            $this->addError('teacherSubjects', 'Select at least one teacher and subject.');

            return;
        }

        $eligibleTeacherIds = $this->availableTeachers($school)->pluck('id');

        abort_unless($selectedTeacherIds->diff($eligibleTeacherIds)->isEmpty(), 403);

        $student = $this->student();

        SchoolStudent::updateOrCreate(
            ['student_id' => $student->id, 'school_id' => $school->id],
            ['is_active' => true, 'class_of' => ClassOfCalculator::classOfFromGrade((int) $this->grade, $school->senior_year)],
        );

        foreach ($this->teacherSubjects as $teacherId => $subjects) {
            if ($subjects === []) {
                continue;
            }

            foreach ($subjects as $subject) {
                StudentTeacher::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'teacher_id' => (int) $teacherId,
                        'school_id' => $school->id,
                        'subject' => $subject,
                    ],
                    ['is_active' => true, 'role' => TeacherRole::Primary->value],
                );
            }
        }

        Flux::toast("You're all set at {$school->name}.");

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        $currentSchool = $this->student()?->current_school;
        $selectedSchool = $this->selected_school_id !== null ? SchoolModel::find($this->selected_school_id) : null;

        return view('livewire.sfdi.school', [
            'currentSchool' => $currentSchool,
            'selectedSchool' => $selectedSchool,
            'gradeOptions' => self::GRADES,
            'availableTeachers' => $selectedSchool !== null ? $this->availableTeachers($selectedSchool) : collect(),
            'subjectOptions' => Subject::cases(),
            'schoolSuggestions' => $this->selected_school_id === null
                ? SchoolMatcher::suggestions(
                    $this->school_search,
                    $this->geostate_id !== '' ? (int) $this->geostate_id : null,
                    $this->zip_code,
                    null,
                )
                : collect(),
        ]);
    }

    /**
     * @return Collection<int, Teacher>
     */
    private function availableTeachers(SchoolModel $school): Collection
    {
        return $school->teachers()
            ->wherePivot('is_active', true)
            ->whereNotNull('school_teacher.verified_at')
            ->with('user')
            ->get()
            ->sortBy(fn ($teacher) => $teacher->user->last_name)
            ->values();
    }

    private function student(): ?Student
    {
        return Auth::user()->student;
    }
}
