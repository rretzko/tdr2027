<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class MergeStudents extends Component
{
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

    /** @return Collection<int, Student> */
    private function searchStudents(string $raw, ?int $excludeId): Collection
    {
        $search = trim($raw);

        if (mb_strlen($search) < 2) {
            return new Collection;
        }

        return Student::query()
            ->join('users', 'users.id', '=', 'students.user_id')
            ->when($excludeId !== null, fn ($q) => $q->where('students.id', '!=', $excludeId))
            ->where(fn ($q) => $q
                ->where('users.first_name', 'like', "%{$search}%")
                ->orWhere('users.last_name', 'like', "%{$search}%")
            )
            ->select('students.*')
            ->with(['user', 'schools' => fn ($q) => $q->wherePivot('is_active', true)])
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->limit(10)
            ->get();
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
            'user',
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
            'user',
            'schools' => fn ($q) => $q->wherePivot('is_active', true),
            'emergencyContacts',
            'homeAddress',
        ])->find($this->loserId);
    }

    public function selectWinner(int $id): void
    {
        $this->winnerId = $id;
        $this->winnerSearch = '';
        unset($this->winner);
    }

    public function selectLoser(int $id): void
    {
        $this->loserId = $id;
        $this->loserSearch = '';
        unset($this->loser);
    }

    public function clearWinner(): void
    {
        $this->winnerId = null;
        $this->winnerSearch = '';
        unset($this->winner);
    }

    public function clearLoser(): void
    {
        $this->loserId = null;
        $this->loserSearch = '';
        unset($this->loser);
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
        $loser  = $this->loser;

        if ($winner === null || $loser === null || $winner->id === $loser->id) {
            return;
        }

        $loserName = $loser->user->first_name.' '.$loser->user->last_name;

        DB::transaction(function () use ($winner, $loser): void {
            $this->performMerge($winner, $loser);
        });

        $this->modal('merge-confirm')->close();

        $this->winnerId = null;
        $this->loserId  = null;
        $this->winnerSearch = '';
        $this->loserSearch  = '';
        unset($this->winner, $this->loser);

        Flux::toast(variant: 'success', text: "{$loserName} merged and deleted successfully.");
    }

    private function performMerge(Student $winner, Student $loser): void
    {
        // school_student — unique on (student_id, school_id)
        $winnerSchoolIds = DB::table('school_student')
            ->where('student_id', $winner->id)
            ->pluck('school_id')
            ->all();

        foreach (DB::table('school_student')->where('student_id', $loser->id)->get() as $row) {
            if (in_array($row->school_id, $winnerSchoolIds, true)) {
                DB::table('school_student')->where('id', $row->id)->delete();
            } else {
                DB::table('school_student')->where('id', $row->id)->update(['student_id' => $winner->id]);
            }
        }

        // student_teacher — unique on (student_id, teacher_id, school_id, subject)
        $winnerKeys = DB::table('student_teacher')
            ->where('student_id', $winner->id)
            ->get(['teacher_id', 'school_id', 'subject'])
            ->map(fn (object $r): string => "{$r->teacher_id}:{$r->school_id}:{$r->subject}")
            ->all();

        foreach (DB::table('student_teacher')->where('student_id', $loser->id)->get() as $row) {
            if (in_array("{$row->teacher_id}:{$row->school_id}:{$row->subject}", $winnerKeys, true)) {
                DB::table('student_teacher')->where('id', $row->id)->delete();
            } else {
                DB::table('student_teacher')->where('id', $row->id)->update(['student_id' => $winner->id]);
            }
        }

        // emergency_contacts — bypass soft-delete scope so deleted rows are also reassigned
        DB::table('emergency_contacts')->where('student_id', $loser->id)->update(['student_id' => $winner->id]);

        // home_address — keep winner's if they already have one
        if ($winner->homeAddress()->exists()) {
            DB::table('home_addresses')->where('student_id', $loser->id)->delete();
        } else {
            DB::table('home_addresses')->where('student_id', $loser->id)->update(['student_id' => $winner->id]);
        }

        // Delete loser Student, then its User if it has no Teacher record
        $loserUserId = $loser->user_id;
        $loser->delete();

        $loserUser = User::find($loserUserId);

        if ($loserUser !== null && ! $loserUser->teacher()->exists()) {
            $loserUser->pageVisits()->delete();
            $loserUser->phones()->delete();
            $loserUser->socialAccounts()->delete();
            $loserUser->delete();
        }
    }

    // -----------------------------------------------------------------------
    // Potential duplicates list
    // -----------------------------------------------------------------------

    /**
     * @return array<int, array{
     *     first_name: string,
     *     last_name: string,
     *     strength: string,
     *     winner_id: int,
     *     loser_id: int,
     *     winner_email: string,
     *     loser_email: string,
     *     winner_birthday: string|null,
     *     loser_birthday: string|null,
     *     winner_schools: string,
     *     loser_schools: string,
     * }>
     */
    #[Computed]
    public function potentialDuplicates(): array
    {
        $groups = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->selectRaw('u.first_name, u.last_name, GROUP_CONCAT(s.id ORDER BY s.id) as ids')
            ->groupBy('u.first_name', 'u.last_name')
            ->havingRaw('COUNT(s.id) > 1')
            ->orderBy('u.last_name')
            ->orderBy('u.first_name')
            ->get();

        if ($groups->isEmpty()) {
            return [];
        }

        $allIds = $groups
            ->flatMap(fn ($g) => explode(',', $g->ids))
            ->map(fn ($id) => (int) $id)
            ->all();

        $studentData = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->whereIn('s.id', $allIds)
            ->select(['s.id', 's.birthday', 'u.email'])
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

        $pairs = [];

        foreach ($groups as $group) {
            $ids      = array_map('intval', explode(',', $group->ids));
            $winnerId = $ids[0];
            $loserId  = $ids[1] ?? null;

            if ($loserId === null) {
                continue;
            }

            $winner = $studentData->get($winnerId);
            $loser  = $studentData->get($loserId);

            if ($winner === null || $loser === null) {
                continue;
            }

            $sameBirthday = $winner->birthday !== null
                && $loser->birthday !== null
                && $winner->birthday === $loser->birthday;

            $pairs[] = [
                'first_name'      => $group->first_name,
                'last_name'       => $group->last_name,
                'strength'        => $sameBirthday ? 'strong' : 'weak',
                'winner_id'       => $winnerId,
                'loser_id'        => $loserId,
                'winner_email'    => $winner->email,
                'loser_email'     => $loser->email,
                'winner_birthday' => $winner->birthday ? Carbon::parse($winner->birthday)->format('M j, Y') : null,
                'loser_birthday'  => $loser->birthday ? Carbon::parse($loser->birthday)->format('M j, Y') : null,
                'winner_schools'  => $schoolData->get((string) $winnerId) ?? '—',
                'loser_schools'   => $schoolData->get((string) $loserId) ?? '—',
            ];
        }

        usort($pairs, fn ($a, $b): int => strcmp($b['strength'], $a['strength'])); // strong before weak

        return $pairs;
    }

    public function refreshDuplicates(): void
    {
        unset($this->potentialDuplicates);
    }

    public function preselectPair(int $winnerId, int $loserId): void
    {
        $this->winnerId     = $winnerId;
        $this->loserId      = $loserId;
        $this->winnerSearch = '';
        $this->loserSearch  = '';
        unset($this->winner, $this->loser);
        $this->dispatch('pair-preselected');
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    public function render(): View
    {
        return view('livewire.founder.merge-students', [
            'winnerResults' => $this->winnerResults(),
            'loserResults'  => $this->loserResults(),
        ]);
    }
}
