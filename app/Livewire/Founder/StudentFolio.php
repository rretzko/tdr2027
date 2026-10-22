<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Enums\EventStatus;
use App\Models\Candidate;
use App\Models\LoginEvent;
use App\Models\Pivots\StudentTeacher;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AutoEnrollmentService;
use App\Services\StudentFolioService;
use App\Support\StudentMatcher;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Founder support lookup for "the student registered but isn't on my roster"
 * and "the student is logged in but can't see the event" calls. Shows the
 * student's account, school and teacher links, and a per-Version eligibility
 * checklist (StudentFolioService). The only write is reEnroll(), which just
 * re-runs the same AutoEnrollmentService path the observers already use.
 *
 * @property-read ?Student $student
 */
#[Layout('components.layouts.app')]
class StudentFolio extends Component
{
    public string $search = '';

    #[Url(as: 'student')]
    public ?int $studentId = null;

    /** @return Collection<int, Student> */
    public function results(): Collection
    {
        $search = trim($this->search);

        if (mb_strlen($search) < 2) {
            return new Collection;
        }

        return Student::query()
            ->join('users', 'users.id', '=', 'students.user_id')
            ->where(fn ($q) => $q
                ->where('users.first_name', 'like', "%{$search}%")
                ->orWhere('users.last_name', 'like', "%{$search}%")
                ->orWhere('users.email', 'like', "%{$search}%")
                ->orWhereRaw("CONCAT(users.first_name, ' ', users.last_name) like ?", ["%{$search}%"])
            )
            ->select('students.*')
            ->with(['user', 'schools' => fn ($q) => $q->wherePivot('is_active', true), 'teachers.user'])
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->limit(15)
            ->get();
    }

    #[Computed]
    public function student(): ?Student
    {
        if ($this->studentId === null) {
            return null;
        }

        return Student::with([
            'user.pronoun',
            'schools',
            'homeSchool',
            'homeAddress',
            'emergencyContacts',
            'voicePart',
            'instrument',
        ])->find($this->studentId);
    }

    public function select(int $id): void
    {
        $this->studentId = $id;
        $this->search = '';
        unset($this->student);
    }

    public function clear(): void
    {
        $this->studentId = null;
        unset($this->student);
    }

    /**
     * Re-runs AutoEnrollmentService for every teacher this student is actively
     * linked to — the fix when every checklist gate now passes (e.g. after a
     * teacher got verified) but no observer fired to create the Candidate.
     * Always available; reports when nothing could be enrolled rather than
     * pre-hiding the button.
     */
    public function reEnroll(AutoEnrollmentService $autoEnrollment): void
    {
        $student = $this->student;
        abort_if($student === null, 404);

        $before = Candidate::where('student_id', $student->id)->count();

        $teacherIds = StudentTeacher::where('student_id', $student->id)
            ->where('is_active', true)
            ->distinct()
            ->pluck('teacher_id');

        Teacher::whereIn('id', $teacherIds)->get()
            ->each(fn (Teacher $teacher) => $autoEnrollment->enrollNewStudentIntoInvitedActiveVersions($student, $teacher));

        $created = Candidate::where('student_id', $student->id)->count() - $before;
        $name = $student->user->first_name;

        if ($created > 0) {
            Flux::toast(variant: 'success', text: "Enrolled {$name} in {$created} ".str('version')->plural($created).'.');
        } else {
            Flux::toast(variant: 'warning', text: "No new enrollments for {$name} — see the failing checks below.");
        }

        unset($this->student);
    }

    public function render(StudentFolioService $folio): View
    {
        $student = $this->student;

        return view('livewire.founder.student-folio', [
            'results' => $student === null ? $this->results() : new Collection,
            'rosterRows' => $student !== null ? $folio->rosterVisibility($student) : [],
            'versionDiagnostics' => $student !== null ? $folio->versionDiagnostics($student) : [],
            'candidateHistory' => $student !== null ? $this->candidateHistory($student) : new Collection,
            'lastLogin' => $student !== null ? LoginEvent::where('user_id', $student->user_id)->latest('created_at')->first() : null,
            'loginCount' => $student !== null ? LoginEvent::where('user_id', $student->user_id)->count() : 0,
            'duplicates' => $student !== null ? $this->duplicates($student) : collect(),
        ]);
    }

    /** @return Collection<int, Candidate> */
    private function candidateHistory(Student $student): Collection
    {
        return Candidate::where('student_id', $student->id)
            ->whereHas('version', fn ($q) => $q->where('status', '!=', EventStatus::Active->value))
            ->with(['version.event', 'teacher.user', 'school'])
            ->get()
            ->sortByDesc('version_id')
            ->values();
    }

    /** @return SupportCollection<int, array{student: Student, tier: string}> */
    private function duplicates(Student $student): SupportCollection
    {
        $contact = $student->emergencyContacts->first();

        return StudentMatcher::suggestions(
            $student->user->first_name,
            $student->user->last_name,
            $student->getRawOriginal('birthday'),
            $contact?->email,
            $contact?->cell_phone,
        )
            ->reject(fn (array $match): bool => $match['student']->id === $student->id)
            ->values();
    }
}
