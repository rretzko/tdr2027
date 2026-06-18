<?php

declare(strict_types=1);

namespace App\Livewire\Students;

use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\StudentTeacher;
use App\Models\Teacher;
use App\Support\ClassOfCalculator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
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

    public ?int $editingRowId = null;

    public string $edit_subject = '';

    public string $edit_role = '';

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

    public function edit(int $rowId): void
    {
        $row = StudentTeacher::where('id', $rowId)
            ->where('teacher_id', $this->teacher()->id)
            ->firstOrFail();

        $this->editingRowId = $row->id;
        $this->edit_subject = (string) $row->getRawOriginal('subject');
        $this->edit_role = (string) $row->getRawOriginal('role');

        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $row = StudentTeacher::where('id', $this->editingRowId)
            ->where('teacher_id', $this->teacher()->id)
            ->firstOrFail();

        $subjectValues = array_map(fn (Subject $subject) => $subject->value, Subject::cases());

        $this->validate([
            'edit_subject' => ['required', Rule::in($subjectValues)],
            'edit_role' => ['required', Rule::in([TeacherRole::Primary->value, TeacherRole::Coteacher->value])],
        ]);

        $row->update([
            'subject' => $this->edit_subject,
            'role' => $this->edit_role,
        ]);

        $this->editingRowId = null;
        $this->modal('edit-student')->close();
    }

    public function render(): View
    {
        $rows = $this->rows();

        return view('livewire.students.index', [
            'rows' => $rows,
            'gradeByRowId' => $this->gradeByRowId($rows),
            'subjectOptions' => Subject::cases(),
        ]);
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
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
            ->with(['student.user', 'school']);

        if ($this->search !== '') {
            $query->where(fn ($q) => $q->where('users.first_name', 'like', "%{$this->search}%")
                ->orWhere('users.last_name', 'like', "%{$this->search}%"));
        }

        match ($this->sortColumn) {
            'school' => $query->orderBy('schools.name', $this->sortDirection),
            'subject' => $query->orderBy('student_teacher.subject', $this->sortDirection),
            default => $query->orderBy('users.last_name', $this->sortDirection)->orderBy('users.first_name', $this->sortDirection),
        };

        return $query->paginate(15);
    }

    /**
     * Grade depends on the student's class_of at the specific school this row
     * belongs to, not necessarily their currently-active school, so it's looked
     * up per (student, school) pair rather than via Student::getGradeAttribute().
     *
     * @param  LengthAwarePaginator<int, StudentTeacher>  $rows
     * @return array<int, int|null>
     */
    private function gradeByRowId(LengthAwarePaginator $rows): array
    {
        $studentIds = $rows->getCollection()->pluck('student_id');
        $schoolIds = $rows->getCollection()->pluck('school_id');

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
