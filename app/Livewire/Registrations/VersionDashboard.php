<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\HasCandidateChecklist;
use App\Models\Candidate;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\CandidateService;
use App\Services\EligibilityService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class VersionDashboard extends Component
{
    use HasCandidateChecklist;

    public Version $version;

    public string $enroll_student_id = '';

    public string $enroll_voice_part_id = '';

    public function mount(Version $version): void
    {
        $this->version = $version;
    }

    public function enroll(EligibilityService $eligibility, CandidateService $candidates): void
    {
        $this->validate([
            'enroll_student_id' => ['required', 'integer', 'exists:students,id'],
            'enroll_voice_part_id' => ['required', 'integer', 'exists:voice_parts,id'],
        ]);

        $teacher = $this->teacher();

        if ($eligibility->isBlockedByRejectedObligations($this->version, $teacher)) {
            $this->addError('enroll_student_id', 'You have rejected this Version\'s obligations, so you cannot enroll students until you accept them again.');

            return;
        }

        $student = Student::findOrFail((int) $this->enroll_student_id);

        $schoolId = $eligibility->resolveSchool($student, $teacher);

        if ($schoolId === null) {
            $this->addError('enroll_student_id', 'No shared active school found between you and this student.');

            return;
        }

        $candidate = $candidates->enroll(
            $this->version,
            $student,
            $teacher,
            $schoolId,
            (int) $this->enroll_voice_part_id,
        );

        $this->enroll_student_id = '';
        $this->enroll_voice_part_id = '';
        $this->resetValidation();

        Flux::toast("{$candidate->program_name} enrolled as {$candidate->ref}.");
    }

    public function withdraw(CandidateService $candidates, int $candidateId): void
    {
        $candidate = Candidate::where('id', $candidateId)
            ->where('teacher_id', $this->teacher()->id)
            ->firstOrFail();

        $name = $candidate->program_name;
        $candidates->withdraw($candidate);

        Flux::toast("{$name} has been withdrawn.");
    }

    public function refreshStatus(CandidateService $candidates, int $candidateId): void
    {
        $candidate = Candidate::where('id', $candidateId)
            ->where('teacher_id', $this->teacher()->id)
            ->with(['student.user', 'student.homeAddress', 'student.emergencyContacts'])
            ->firstOrFail();

        $checklistDefs = $this->checklistDefs($this->version);
        $candidates->recalculateStatus($candidate, $checklistDefs);

        Flux::toast("{$candidate->program_name} status updated.");
    }

    public function render(): View
    {
        $teacher = $this->teacher();

        $myCandidates = Candidate::where('version_id', $this->version->id)
            ->where('teacher_id', $teacher->id)
            ->with(['student.user', 'student.homeAddress', 'student.emergencyContacts', 'voicePart'])
            ->get()
            ->sortBy('program_name');

        $eligibleStudents = app(EligibilityService::class)->eligibleStudents($this->version, $teacher);

        $upcomingDates = $this->version->dates()
            ->where('start_at', '>=', now())
            ->orderBy('start_at')
            ->limit(6)
            ->get();

        $voiceParts = VoicePart::ordered()->get();

        $checklistDefs = $this->checklistDefs($this->version);

        $obligationsRejected = app(EligibilityService::class)->isBlockedByRejectedObligations($this->version, $teacher);

        return view('livewire.registrations.version-dashboard', compact(
            'myCandidates', 'eligibleStudents', 'upcomingDates', 'voiceParts', 'checklistDefs', 'obligationsRejected',
        ));
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
