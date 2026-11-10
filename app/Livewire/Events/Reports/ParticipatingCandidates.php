<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PhoneType;
use App\Models\Candidate;
use App\Models\CandidateFeeWaiver;
use App\Models\PaymentAllocation;
use App\Models\Phone;
use App\Models\School;
use App\Models\Teacher;
use App\Models\Version;
use App\Services\CandidateFeeCalculator;
use App\Services\CandidateFeeWaiverService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class ParticipatingCandidates extends Component
{
    use ScopesReports;
    use WithPagination;

    /**
     * Candidate rows per page — the report has no natural row-count cap
     * otherwise, and rendering every candidate at once (in duplicate, for
     * the mobile-card and desktop-table layouts) is what made this page
     * take 20+ seconds to respond on large rosters.
     */
    private const PER_PAGE = 25;

    public Version $version;

    #[Url]
    public string $search = '';

    #[Url]
    public string $schoolFilter = '';

    #[Url]
    public string $gradeFilter = '';

    #[Url]
    public string $voicePartFilter = '';

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

    /** '' = all candidates, 'waived' = only candidates with any fee waived. */
    #[Url]
    public string $waiverFilter = '';

    #[Locked]
    public ?int $waivingCandidateId = null;

    #[Locked]
    public string $waivingCandidateName = '';

    /**
     * Completed payments already allocated to the candidate being edited —
     * drives the modal's "this will leave a credit" advisory only.
     */
    #[Locked]
    public int $waivingPaidCents = 0;

    /** @var list<string> FeeType values checked in the waiver modal */
    public array $waive_fee_types = [];

    public string $waive_reason = '';

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        $this->authorizeReports($version, $roles);

        $this->version = $version;
    }

    /**
     * School and Teacher are section headers now (shown once per group,
     * not per row), so they're no longer independently sortable — only
     * the order of candidates within each teacher's group can be toggled.
     */
    public function toggleSort(): void
    {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSchoolFilter(): void
    {
        $this->resetPage();
    }

    public function updatedGradeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedVoicePartFilter(): void
    {
        $this->resetPage();
    }

    public function updatedWaiverFilter(): void
    {
        $this->resetPage();
    }

    public function openWaivers(int $candidateId, VersionRoleAssignmentService $roles): void
    {
        $this->authorizeFeeWaivers($roles);

        $candidate = $this->scopedCandidate($candidateId);
        $waivers = $candidate->feeWaivers()->get();

        $this->waivingCandidateId = $candidate->id;
        $this->waivingCandidateName = $candidate->student->user->name;
        $this->waive_fee_types = $waivers->map(fn (CandidateFeeWaiver $w): string => (string) $w->getRawOriginal('fee_type'))->values()->all();
        $this->waive_reason = (string) ($waivers->first()->reason ?? '');
        $this->waivingPaidCents = (int) PaymentAllocation::where('candidate_id', $candidate->id)
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', PaymentTransactionStatus::Completed->value))
            ->sum('amount');

        $this->resetErrorBag();
        $this->modal('candidate-fee-waivers')->show();
    }

    public function saveWaivers(VersionRoleAssignmentService $roles, CandidateFeeWaiverService $waiverService): void
    {
        $this->authorizeFeeWaivers($roles);
        abort_if($this->waivingCandidateId === null, 400);

        $candidate = $this->scopedCandidate($this->waivingCandidateId);
        $manageable = array_column($this->waivableFeeTypes(), 'type');

        $validated = $this->validate([
            'waive_fee_types' => ['array'],
            'waive_fee_types.*' => ['string', Rule::in(array_map(fn (FeeType $t): string => $t->value, $manageable))],
            'waive_reason' => [Rule::requiredIf($this->waive_fee_types !== []), 'nullable', 'string', 'max:500'],
        ], [
            'waive_reason.required' => 'Please give a reason for waiving these fees.',
        ]);

        $result = $waiverService->sync(
            $candidate,
            array_map(fn (string $value): FeeType => FeeType::from($value), $validated['waive_fee_types']),
            $manageable,
            trim((string) $validated['waive_reason']),
            Auth::user(),
        );

        $name = $this->waivingCandidateName;
        $this->waivingCandidateId = null;
        $this->modal('candidate-fee-waivers')->close();

        $labels = fn (array $types): string => implode(', ', array_map(fn (FeeType $t): string => $t->label(), $types));

        $added = $result['added'];
        $removed = $result['removed'];

        if ($added !== [] && $removed !== []) {
            $message = "Fee waivers updated for {$name}.";
        } elseif ($added !== []) {
            $message = "Waived for {$name}: {$labels($added)}.";
        } elseif ($removed !== []) {
            $message = "Fee waiver removed for {$name}: {$labels($removed)}.";
        } else {
            $message = "Fee waivers saved for {$name}.";
        }

        Flux::toast($message, variant: 'success');
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
        $this->modal('candidate-edit-form')->show();
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
        $filteredRows = self::filterRows($allRows, $this->search, $this->schoolFilter, $this->gradeFilter, $this->voicePartFilter, $this->waiverFilter);
        $orderedRows = self::flattenGroups(self::groupRows($filteredRows, $this->sortDirection));

        $page = $this->getPage();
        $pageRows = $orderedRows->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();

        $paginator = new LengthAwarePaginator(
            $pageRows,
            $orderedRows->count(),
            self::PER_PAGE,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );

        return view('livewire.events.reports.participating-candidates', [
            'schoolGroups' => self::groupRows($pageRows, $this->sortDirection),
            'candidatesPaginator' => $paginator,
            'schoolOptions' => $allRows->pluck('candidate.school.name')->filter()->unique()->sort()->values(),
            'gradeOptions' => $allRows->pluck('grade')->filter(fn ($g) => $g !== null)->unique()->sort()->values(),
            'availableVoiceParts' => $this->version->availableVoiceParts(),
            'canWaiveFees' => app(VersionRoleAssignmentService::class)->canWaiveFees(Auth::user(), $this->version),
            'waivableFeeTypes' => $this->waivableFeeTypes(),
        ]);
    }

    /**
     * Fee types the waiver modal offers — only those this Version actually
     * charges (a $0 fee has nothing to waive).
     *
     * @return list<array{type: FeeType, cents: int}>
     */
    private function waivableFeeTypes(): array
    {
        $calculator = app(CandidateFeeCalculator::class);

        return array_values(array_filter(
            array_map(fn (FeeType $type): array => ['type' => $type, 'cents' => $calculator->configuredFeeCents($this->version, $type)], FeeType::cases()),
            fn (array $entry): bool => $entry['cents'] > 0,
        ));
    }

    private function authorizeFeeWaivers(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canWaiveFees(Auth::user(), $this->version), 403);
    }

    /**
     * Flattens the grouped school→teacher→candidate structure back into a
     * flat list of candidate rows in the same order groupRows() displays
     * them — lets render() paginate a fixed-size page of rows without
     * losing the alphabetical school/teacher ordering (or the
     * $sortDirection candidate ordering within each teacher).
     *
     * @param  Collection<int, mixed>  $schoolGroups
     * @return Collection<int, mixed>
     */
    private static function flattenGroups(Collection $schoolGroups): Collection
    {
        return $schoolGroups
            ->flatMap(fn (array $schoolGroup): Collection => $schoolGroup['teacherGroups'])
            ->flatMap(fn (array $teacherGroup): Collection => $teacherGroup['candidates'])
            ->values();
    }

    /**
     * @param  list<int>|null  $countyIds
     * @return Collection<int, mixed>
     */
    public static function baseRows(Version $version, ?array $countyIds): Collection
    {
        $query = Candidate::where('version_id', $version->id)
            ->where('status', CandidateStatus::Registered->value)
            ->with(['student.user', 'student.schools', 'teacher.user.phones', 'school', 'voicePart', 'feeWaivers']);

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
    public static function filterRows(Collection $rows, string $search, string $schoolFilter, string $gradeFilter, string $voicePartFilter, string $waiverFilter = ''): Collection
    {
        if ($waiverFilter === 'waived') {
            $rows = $rows->filter(fn (array $row): bool => $row['candidate']->feeWaivers->isNotEmpty());
        }

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

        return $rows->values();
    }

    /**
     * Groups candidates by school, then by teacher within that school —
     * each shown once as a section header rather than repeated per row —
     * always alphabetical at both levels. $sortDirection only controls the
     * order of candidates within each teacher's group.
     *
     * Each row of the returned collection is
     * array{school: ?School, teacherGroups: Collection<int, array{teacher: ?Teacher, candidates: Collection<int, mixed>}>}.
     *
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    public static function groupRows(Collection $rows, string $sortDirection): Collection
    {
        $schoolSortValue = function (Collection $schoolRows): string {
            $school = self::schoolOf($schoolRows->first());

            return $school !== null ? mb_strtolower($school->name) : '';
        };

        $teacherSortValue = function (Collection $teacherRows): string {
            $teacher = self::teacherOf($teacherRows->first());

            return $teacher !== null ? mb_strtolower($teacher->user->name) : '';
        };

        return $rows->groupBy(fn (array $row): int => self::candidateOf($row)->school_id)
            ->sortBy($schoolSortValue)
            ->map(fn (Collection $schoolRows): array => self::buildSchoolGroup($schoolRows, $sortDirection, $teacherSortValue))
            ->values();
    }

    /**
     * Narrows a `mixed` baseRows()/filterRows() row to its Candidate —
     * Collection<TValue> is invariant in PHPStan, so declaring precise
     * nested array-shape generics on the row collections themselves
     * cascades into false "expects X, X given" mismatches; these small
     * helpers narrow at each boundary with a fixed declared return type
     * instead, which PHPStan trusts without re-deriving it through nested
     * closures.
     */
    private static function candidateOf(mixed $row): Candidate
    {
        return $row['candidate'];
    }

    private static function schoolOf(mixed $row): ?School
    {
        return $row === null ? null : self::candidateOf($row)->school;
    }

    private static function teacherOf(mixed $row): ?Teacher
    {
        return $row === null ? null : self::candidateOf($row)->teacher;
    }

    /**
     * @param  Collection<int, mixed>  $schoolRows
     */
    private static function buildSchoolGroup(Collection $schoolRows, string $sortDirection, callable $teacherSortValue): array
    {
        $teacherGroups = $schoolRows->groupBy(fn (array $row): int => self::candidateOf($row)->teacher_id)
            ->sortBy($teacherSortValue)
            ->map(fn (Collection $teacherRows): array => self::buildTeacherGroup($teacherRows, $sortDirection))
            ->values();

        return [
            'school' => self::schoolOf($schoolRows->first()),
            'teacherGroups' => $teacherGroups,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $teacherRows
     */
    private static function buildTeacherGroup(Collection $teacherRows, string $sortDirection): array
    {
        $candidateNameValue = fn (array $row): string => mb_strtolower(self::candidateOf($row)->student->user->name);

        $candidates = $sortDirection === 'desc'
            ? $teacherRows->sortByDesc($candidateNameValue)
            : $teacherRows->sortBy($candidateNameValue);

        return [
            'teacher' => self::teacherOf($teacherRows->first()),
            'candidates' => $candidates->values(),
        ];
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
