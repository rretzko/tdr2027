<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Enums\CandidateStatus;
use App\Enums\PhoneType;
use App\Models\Candidate;
use App\Models\Phone;
use App\Models\School;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ParticipatingCandidates extends Component
{
    use ScopesReports;

    public Version $version;

    #[Url]
    public string $search = '';

    #[Url]
    public string $schoolFilter = '';

    #[Url]
    public string $gradeFilter = '';

    #[Url]
    public string $voicePartFilter = '';

    public string $sortColumn = 'candidate';

    public string $sortDirection = 'asc';

    public ?int $editingCandidateId = null;

    public string $edit_first_name = '';

    public string $edit_middle_name = '';

    public string $edit_last_name = '';

    public string $edit_voice_part_id = '';

    public string $edit_home_phone = '';

    public string $edit_cell_phone = '';

    public string $edit_emergency_contact_id = '';

    /** @var list<array{id: int, name: string}> */
    public array $editEmergencyContactOptions = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        $this->authorizeReports($version, $roles);

        $this->version = $version;
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function edit(int $candidateId): void
    {
        $candidate = $this->scopedCandidate($candidateId);
        $user = $candidate->student->user;

        $this->editingCandidateId = $candidate->id;
        $this->edit_first_name = $user->first_name;
        $this->edit_middle_name = $user->middle_name ?? '';
        $this->edit_last_name = $user->last_name;
        $this->edit_voice_part_id = (string) $candidate->voice_part_id;
        $this->edit_home_phone = Phone::where('user_id', $user->id)->where('type', PhoneType::Home->value)->value('raw_number') ?? '';
        $this->edit_cell_phone = (string) $user->cell_phone;
        $this->edit_emergency_contact_id = (string) $candidate->emergency_contact_id;
        $this->editEmergencyContactOptions = $candidate->student->emergencyContacts
            ->map(fn ($contact): array => ['id' => $contact->id, 'name' => $contact->name])
            ->all();
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_if($this->editingCandidateId === null, 400);

        $candidate = $this->scopedCandidate($this->editingCandidateId);
        $validVoicePartIds = $this->version->availableVoiceParts()->pluck('id')->all();
        $validEmergencyContactIds = $candidate->student->emergencyContacts->pluck('id')->all();

        $validated = $this->validate([
            'edit_first_name' => ['required', 'string', 'max:100'],
            'edit_middle_name' => ['nullable', 'string', 'max:100'],
            'edit_last_name' => ['required', 'string', 'max:100'],
            'edit_voice_part_id' => ['required', 'integer', Rule::in($validVoicePartIds)],
            'edit_home_phone' => ['nullable', 'string', 'max:20'],
            'edit_cell_phone' => ['nullable', 'string', 'max:20'],
            'edit_emergency_contact_id' => ['nullable', 'integer', Rule::in($validEmergencyContactIds)],
        ]);

        $user = $candidate->student->user;
        $user->update([
            'first_name' => $validated['edit_first_name'],
            'middle_name' => $validated['edit_middle_name'] !== '' ? $validated['edit_middle_name'] : null,
            'last_name' => $validated['edit_last_name'],
            'cell_phone' => $validated['edit_cell_phone'] !== '' ? $validated['edit_cell_phone'] : null,
        ]);

        if ($validated['edit_home_phone'] !== '' && $validated['edit_home_phone'] !== null) {
            Phone::updateOrCreate(
                ['user_id' => $user->id, 'type' => PhoneType::Home->value],
                ['raw_number' => $validated['edit_home_phone']],
            );
        } else {
            Phone::where('user_id', $user->id)->where('type', PhoneType::Home->value)->delete();
        }

        $candidate->update([
            'voice_part_id' => (int) $validated['edit_voice_part_id'],
            'emergency_contact_id' => $validated['edit_emergency_contact_id'] !== '' ? (int) $validated['edit_emergency_contact_id'] : null,
        ]);

        $this->editingCandidateId = null;
        $this->modal('candidate-edit-form')->close();

        Flux::toast('Candidate updated.', variant: 'success');
    }

    public function remove(int $candidateId, string $toStatus): void
    {
        abort_unless(in_array($toStatus, [CandidateStatus::Withdrew->value, CandidateStatus::TeacherWithdrawn->value], true), 400);

        $candidate = $this->scopedCandidate($candidateId);
        $candidate->update(['status' => $toStatus]);

        Flux::toast('Candidate removed from the Version.', variant: 'success');
    }

    public function render(): View
    {
        $allRows = self::baseRows($this->version, $this->reportCountyIds);

        return view('livewire.events.reports.participating-candidates', [
            'rows' => self::filterAndSort($allRows, $this->search, $this->schoolFilter, $this->gradeFilter, $this->voicePartFilter, $this->sortColumn, $this->sortDirection),
            'schoolOptions' => $allRows->pluck('candidate.school.name')->filter()->unique()->sort()->values(),
            'gradeOptions' => $allRows->pluck('grade')->filter(fn ($g) => $g !== null)->unique()->sort()->values(),
            'availableVoiceParts' => $this->version->availableVoiceParts(),
        ]);
    }

    /**
     * @param  list<int>|null  $countyIds
     * @return Collection<int, mixed>
     */
    public static function baseRows(Version $version, ?array $countyIds): Collection
    {
        $query = Candidate::where('version_id', $version->id)
            ->where('status', CandidateStatus::Registered->value)
            ->with(['student.user', 'teacher.user.phones', 'school', 'voicePart']);

        if ($countyIds !== null) {
            $query->whereHas('school', fn ($q) => $q->whereIn('county_id', $countyIds));
        }

        return $query->get()->map(fn (Candidate $candidate): array => [
            'candidate' => $candidate,
            'grade' => $candidate->student->grade,
        ]);
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    public static function filterAndSort(Collection $rows, string $search, string $schoolFilter, string $gradeFilter, string $voicePartFilter, string $sortColumn, string $sortDirection): Collection
    {
        $search = mb_strtolower(trim($search));

        if ($search !== '') {
            $rows = $rows->filter(function (array $row) use ($search): bool {
                $candidate = $row['candidate'];
                $school = $candidate->school;

                return str_contains(mb_strtolower($candidate->student->user->name), $search)
                    || str_contains(mb_strtolower($candidate->teacher->user->name), $search)
                    || ($school instanceof School && str_contains(mb_strtolower($school->name), $search));
            });
        }

        if ($schoolFilter !== '') {
            $rows = $rows->filter(fn (array $row): bool => ($row['candidate']->school->name ?? null) === $schoolFilter);
        }

        if ($gradeFilter !== '') {
            $rows = $rows->filter(fn (array $row): bool => (string) $row['grade'] === $gradeFilter);
        }

        if ($voicePartFilter !== '') {
            $rows = $rows->filter(fn (array $row): bool => (string) $row['candidate']->voice_part_id === $voicePartFilter);
        }

        $sortValue = fn (array $row): string => match ($sortColumn) {
            'school' => mb_strtolower($row['candidate']->school->name ?? ''),
            'teacher' => mb_strtolower($row['candidate']->teacher->user->name),
            default => mb_strtolower($row['candidate']->student->user->name),
        };

        $rows = $sortDirection === 'desc'
            ? $rows->sortByDesc($sortValue)
            : $rows->sortBy($sortValue);

        return $rows->values();
    }

    private function scopedCandidate(int $candidateId): Candidate
    {
        $query = Candidate::where('id', $candidateId)
            ->where('version_id', $this->version->id)
            ->where('status', CandidateStatus::Registered->value);

        if ($this->reportCountyIds !== null) {
            $query->whereHas('school', fn ($q) => $q->whereIn('county_id', $this->reportCountyIds));
        }

        return $query->firstOrFail();
    }
}
