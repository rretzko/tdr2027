<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Enums\PhoneType;
use App\Models\Student;
use App\Services\StudentMergeService;
use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * @property-read ?Student $winner
 * @property-read ?Student $loser
 * @property-read list<string> $discardedPhones
 * @property-read list<array{field: string, winner: string, loser: string}> $profileDifferences
 * @property-read list<array{version_name: string, winner_voice_part: string, loser_voice_part: string, winner_status: string, loser_status: string}> $sharedCandidateDifferences
 * @property-read list<array{strength: string, winner_id: int, loser_id: int, winner_name: string, loser_name: string, winner_email: string, loser_email: string, winner_birthday: string|null, loser_birthday: string|null, winner_schools: string, loser_schools: string, shared_phone: string|null}> $potentialDuplicates
 */
#[Layout('components.layouts.app')]
class MergeStudents extends Component
{
    private const SEARCH_LIMIT = 10;

    public string $winnerSearch = '';

    public string $loserSearch = '';

    public ?int $winnerId = null;

    public ?int $loserId = null;

    // -----------------------------------------------------------------------
    // Search
    // -----------------------------------------------------------------------

    /** @return Collection<int, Student> */
    public function winnerResults(): Collection
    {
        return $this->searchStudents($this->winnerSearch, $this->loserId);
    }

    /** @return Collection<int, Student> */
    public function loserResults(): Collection
    {
        return $this->searchStudents($this->loserSearch, $this->winnerId);
    }

    public function winnerResultCount(): int
    {
        return $this->searchQuery($this->winnerSearch, $this->loserId)?->count() ?? 0;
    }

    public function loserResultCount(): int
    {
        return $this->searchQuery($this->loserSearch, $this->winnerId)?->count() ?? 0;
    }

    /** @return Collection<int, Student> */
    private function searchStudents(string $raw, ?int $excludeId): Collection
    {
        $query = $this->searchQuery($raw, $excludeId);

        if ($query === null) {
            return new Collection;
        }

        $terms = $this->searchTerms($raw);

        // Rank each term: exact first/last name (0), starts-with (1), contains
        // anywhere (2). Summed across terms, so "lee" lists the Lees ahead of
        // Kaylee/Jasleen instead of the alphabetical first ten crowding them out.
        foreach ($terms as $term) {
            $query->orderByRaw(
                'case when users.first_name = ? or users.last_name = ? then 0 when users.first_name like ? or users.last_name like ? then 1 else 2 end',
                [$term, $term, $this->likeEscape($term).'%', $this->likeEscape($term).'%'],
            );
        }

        return $query
            ->select('students.*')
            ->with(['user', 'schools' => fn ($q) => $q->wherePivot('is_active', true)])
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->orderBy('students.id')
            ->limit(self::SEARCH_LIMIT)
            ->get();
    }

    /**
     * Every whitespace-separated term must match first, middle or last name or
     * email, so "Ian Lee" narrows to Ian Lee rather than matching nothing (the
     * whole string never appears in a single column). A purely numeric search
     * is a student id or user id — the ids the merge command and support
     * requests are usually quoted by.
     *
     * @return Builder<Student>|null
     */
    private function searchQuery(string $raw, ?int $excludeId): ?Builder
    {
        $search = trim($raw);

        if ($search === '' || (mb_strlen($search) < 2 && ! ctype_digit($search))) {
            return null;
        }

        $query = Student::query()
            ->join('users', 'users.id', '=', 'students.user_id')
            ->when($excludeId !== null, fn ($q) => $q->where('students.id', '!=', $excludeId));

        if (ctype_digit($search)) {
            return $query->where(fn ($q) => $q
                ->where('students.id', (int) $search)
                ->orWhere('students.user_id', (int) $search)
            );
        }

        foreach ($this->searchTerms($search) as $term) {
            $like = '%'.$this->likeEscape($term).'%';

            $query->where(fn ($q) => $q
                ->where('users.first_name', 'like', $like)
                ->orWhere('users.middle_name', 'like', $like)
                ->orWhere('users.last_name', 'like', $like)
                ->orWhere('users.email', 'like', $like)
            );
        }

        return $query;
    }

    /** @return list<string> */
    private function searchTerms(string $raw): array
    {
        $terms = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);

        return array_slice($terms === false ? [] : $terms, 0, 4);
    }

    private function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    // -----------------------------------------------------------------------
    // Selection
    // -----------------------------------------------------------------------

    #[Computed]
    public function winner(): ?Student
    {
        if ($this->winnerId === null) {
            return null;
        }

        return Student::with([
            'user.phones',
            'voicePart',
            'instrument',
            'schools' => fn ($q) => $q->wherePivot('is_active', true),
            'emergencyContacts',
            'homeAddress',
        ])->find($this->winnerId);
    }

    #[Computed]
    public function loser(): ?Student
    {
        if ($this->loserId === null) {
            return null;
        }

        return Student::with([
            'user.phones',
            'voicePart',
            'instrument',
            'schools' => fn ($q) => $q->wherePivot('is_active', true),
            'emergencyContacts',
            'homeAddress',
        ])->find($this->loserId);
    }

    /**
     * Formatted numbers the merge would delete, shown in the confirm modal.
     *
     * @return list<string>
     */
    #[Computed]
    public function discardedPhones(): array
    {
        if ($this->winner === null || $this->loser === null || $this->winner->id === $this->loser->id) {
            return [];
        }

        return app(StudentMergeService::class)->discardedPhones($this->winner, $this->loser);
    }

    /**
     * Profile fields both students have set to different values; the winner's are kept.
     *
     * @return list<array{field: string, winner: string, loser: string}>
     */
    #[Computed]
    public function profileDifferences(): array
    {
        if ($this->winner === null || $this->loser === null || $this->winner->id === $this->loser->id) {
            return [];
        }

        return app(StudentMergeService::class)->profileDifferences($this->winner, $this->loser);
    }

    /**
     * Same-Version Candidate pairs that differ in voice part or status.
     *
     * @return list<array{version_name: string, winner_voice_part: string, loser_voice_part: string, winner_status: string, loser_status: string}>
     */
    #[Computed]
    public function sharedCandidateDifferences(): array
    {
        if ($this->winner === null || $this->loser === null || $this->winner->id === $this->loser->id) {
            return [];
        }

        return app(StudentMergeService::class)->sharedCandidateDifferences($this->winner, $this->loser);
    }

    public function selectWinner(int $id): void
    {
        $this->winnerId = $id;
        $this->winnerSearch = '';
        unset($this->winner, $this->discardedPhones, $this->profileDifferences, $this->sharedCandidateDifferences);
    }

    public function selectLoser(int $id): void
    {
        $this->loserId = $id;
        $this->loserSearch = '';
        unset($this->loser, $this->discardedPhones, $this->profileDifferences, $this->sharedCandidateDifferences);
    }

    public function clearWinner(): void
    {
        $this->winnerId = null;
        $this->winnerSearch = '';
        unset($this->winner, $this->discardedPhones, $this->profileDifferences, $this->sharedCandidateDifferences);
    }

    public function clearLoser(): void
    {
        $this->loserId = null;
        $this->loserSearch = '';
        unset($this->loser, $this->discardedPhones, $this->profileDifferences, $this->sharedCandidateDifferences);
    }

    // -----------------------------------------------------------------------
    // Merge
    // -----------------------------------------------------------------------

    public function confirmMerge(): void
    {
        if ($this->winner === null || $this->loser === null || $this->winnerId === $this->loserId) {
            return;
        }

        $this->modal('merge-confirm')->show();
    }

    public function merge(): void
    {
        $winner = $this->winner;
        $loser = $this->loser;

        if ($winner === null || $loser === null || $winner->id === $loser->id) {
            return;
        }

        $loserName = $loser->user->first_name.' '.$loser->user->last_name;

        try {
            app(StudentMergeService::class)->merge($winner, $loser);
        } catch (\DomainException $e) {
            $this->modal('merge-confirm')->close();
            Flux::toast(variant: 'danger', text: "{$loserName} can't be merged: {$e->getMessage()}");

            return;
        }

        $this->modal('merge-confirm')->close();

        $this->winnerId = null;
        $this->loserId = null;
        $this->winnerSearch = '';
        $this->loserSearch = '';
        unset($this->winner, $this->loser, $this->discardedPhones, $this->profileDifferences, $this->sharedCandidateDifferences);

        Flux::toast(variant: 'success', text: "{$loserName} merged and deleted successfully.");
    }

    // -----------------------------------------------------------------------
    // Potential duplicates list
    // -----------------------------------------------------------------------

    /**
     * Pairs of students that are probably the same person, strongest first:
     *  - strong: same first+last name and either the same birthday or the same own cell
     *  - phone:  different names but the same own cell number (nicknames, typos)
     *  - weak:   same first+last name only
     *
     * "Own cell" is users.cell_phone or a cell-type phones row. Home phones and
     * emergency-contact numbers are left out because siblings share them.
     *
     * @return list<array{
     *     strength: string,
     *     winner_id: int,
     *     loser_id: int,
     *     winner_name: string,
     *     loser_name: string,
     *     winner_email: string,
     *     loser_email: string,
     *     winner_birthday: string|null,
     *     loser_birthday: string|null,
     *     winner_schools: string,
     *     loser_schools: string,
     *     shared_phone: string|null,
     * }>
     */
    #[Computed]
    public function potentialDuplicates(): array
    {
        /** @var array<string, array{0: int, 1: int}> $pairIds keyed "winnerId-loserId" */
        $pairIds = [];

        $nameGroups = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->selectRaw('GROUP_CONCAT(s.id ORDER BY s.id) as ids')
            ->groupBy('u.first_name', 'u.last_name')
            ->havingRaw('COUNT(s.id) > 1')
            ->get();

        foreach ($nameGroups as $group) {
            $ids = array_map('intval', explode(',', (string) $group->ids));
            $pairIds["{$ids[0]}-{$ids[1]}"] = [$ids[0], $ids[1]];
        }

        $sharedPhones = $this->sharedOwnCellPairs();

        foreach (array_keys($sharedPhones) as $key) {
            [$winnerId, $loserId] = array_map('intval', explode('-', $key));
            $pairIds[$key] = [$winnerId, $loserId];
        }

        if ($pairIds === []) {
            return [];
        }

        $allIds = collect($pairIds)->flatten()->unique()->values()->all();

        $studentData = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->whereIn('s.id', $allIds)
            ->select(['s.id', 's.birthday', 'u.email', 'u.first_name', 'u.last_name'])
            ->get()
            ->keyBy('id');

        $schoolData = DB::table('school_student as ss')
            ->join('schools as sc', 'sc.id', '=', 'ss.school_id')
            ->whereIn('ss.student_id', $allIds)
            ->where('ss.is_active', true)
            ->select(['ss.student_id', 'sc.name'])
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->pluck('name')->join(', '));

        $rank = ['strong' => 0, 'phone' => 1, 'weak' => 2];
        $pairs = [];

        foreach ($pairIds as $key => [$winnerId, $loserId]) {
            $winner = $studentData->get($winnerId);
            $loser = $studentData->get($loserId);

            if ($winner === null || $loser === null) {
                continue;
            }

            $sameName = $winner->first_name === $loser->first_name && $winner->last_name === $loser->last_name;
            $sameBirthday = $winner->birthday !== null && $winner->birthday === $loser->birthday;
            $sharedPhone = $sharedPhones[$key] ?? null;

            $strength = match (true) {
                $sameName && ($sameBirthday || $sharedPhone !== null) => 'strong',
                $sharedPhone !== null => 'phone',
                default => 'weak',
            };

            $pairs[] = [
                'strength' => $strength,
                'winner_id' => $winnerId,
                'loser_id' => $loserId,
                'winner_name' => $winner->first_name.' '.$winner->last_name,
                'loser_name' => $loser->first_name.' '.$loser->last_name,
                'winner_email' => (string) $winner->email,
                'loser_email' => (string) $loser->email,
                'winner_birthday' => $winner->birthday ? Carbon::parse($winner->birthday)->format('M j, Y') : null,
                'loser_birthday' => $loser->birthday ? Carbon::parse($loser->birthday)->format('M j, Y') : null,
                'winner_schools' => $schoolData->get((string) $winnerId) ?? '—',
                'loser_schools' => $schoolData->get((string) $loserId) ?? '—',
                'shared_phone' => $sharedPhone !== null ? PhoneNormalizer::format($sharedPhone) : null,
            ];
        }

        usort($pairs, fn (array $a, array $b): int => [$rank[$a['strength']], $a['winner_name']] <=> [$rank[$b['strength']], $b['winner_name']]);

        return $pairs;
    }

    /**
     * Student pairs whose own cell numbers match, as "lowerId-higherId" => number.
     * users.cell_phone is unique, so a match is either a users.cell_phone
     * against a phones row, or two phones rows.
     *
     * @return array<string, string>
     */
    private function sharedOwnCellPairs(): array
    {
        $ownCells = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->whereNotNull('u.cell_phone')
            ->where('u.cell_phone', '!=', '')
            ->select(['s.id as student_id', 'u.cell_phone as number'])
            ->unionAll(
                DB::table('students as s')
                    ->join('phones as p', 'p.user_id', '=', 's.user_id')
                    ->where('p.type', PhoneType::Cell->value)
                    ->where('p.raw_number', '!=', '')
                    ->select(['s.id as student_id', 'p.raw_number as number'])
            );

        $groups = DB::query()
            ->fromSub($ownCells, 'c')
            ->selectRaw('c.number, GROUP_CONCAT(DISTINCT c.student_id ORDER BY c.student_id) as ids')
            ->groupBy('c.number')
            ->havingRaw('COUNT(DISTINCT c.student_id) > 1')
            ->get();

        $pairs = [];

        foreach ($groups as $group) {
            $ids = array_map('intval', explode(',', (string) $group->ids));
            $pairs["{$ids[0]}-{$ids[1]}"] ??= (string) $group->number;
        }

        return $pairs;
    }

    public function refreshDuplicates(): void
    {
        unset($this->potentialDuplicates);
    }

    public function preselectPair(int $winnerId, int $loserId): void
    {
        $this->winnerId = $winnerId;
        $this->loserId = $loserId;
        $this->winnerSearch = '';
        $this->loserSearch = '';
        unset($this->winner, $this->loser, $this->discardedPhones, $this->profileDifferences, $this->sharedCandidateDifferences);
        $this->dispatch('pair-preselected');
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    public function render(): View
    {
        return view('livewire.founder.merge-students', [
            'winnerResults' => $this->winnerResults(),
            'loserResults' => $this->loserResults(),
            'winnerResultCount' => $this->winnerResultCount(),
            'loserResultCount' => $this->loserResultCount(),
        ]);
    }
}
