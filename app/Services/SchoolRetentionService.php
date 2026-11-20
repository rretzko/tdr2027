<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\VersionDateType;
use App\Models\Candidate;
use App\Models\School;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Year-over-year school retention for the Scorecard's Registration tab: which
 * of the previous Version's participating schools are back, which aren't (and
 * why), and how many of this Version's teachers are new. Schools rather than
 * teachers, since a school that keeps participating under a new teacher is
 * not a loss to the event.
 *
 * "Participating" is CandidateStatus::registrantStates() on both sides, so a
 * closed Version's Accepted/Not Accepted/No Show students count the same as
 * this Version's Registered ones.
 */
final class SchoolRetentionService
{
    public function __construct(
        private readonly VersionInvitationEligibilityService $invitationEligibility,
    ) {}

    /**
     * Null when the event has no earlier Version, or the earlier Version had
     * no participating schools to compare against.
     *
     * @return ?array{
     *     prior: Version,
     *     final: bool,
     *     schools: array{prior: int, retained: int, retainedPercent: int, notBack: int, new: int},
     *     students: array{prior: int, current: int, retainedChange: int, notBackLost: int, newGained: int},
     *     teachers: array{current: int, newToEvent: int, newToEventStudents: int, newToTdr: int},
     *     notBack: list<array{name: string, priorStudents: int, priorTeachers: list<string>, invited: list<array{name: string, email: string, eligible: int, pending: int}>}>,
     *     teacherChanged: list<array{name: string, priorStudents: int, currentStudents: int, was: list<string>, now: list<string>}>,
     *     fewer: list<array{name: string, priorStudents: int, currentStudents: int, change: int, unregistered: int}>,
     * }
     */
    public function forVersion(Version $version, ?Carbon $now = null): ?array
    {
        $now ??= Carbon::now();

        $prior = $this->priorVersion($version);
        if ($prior === null) {
            return null;
        }

        $registrantStates = array_map(fn (CandidateStatus $status): string => $status->value, CandidateStatus::registrantStates());

        $priorRows = Candidate::where('version_id', $prior->id)->whereIn('status', $registrantStates)->toBase()->get(['school_id', 'teacher_id']);
        if ($priorRows->isEmpty()) {
            return null;
        }

        $currentAll = Candidate::where('version_id', $version->id)->toBase()->get(['school_id', 'teacher_id', 'status']);
        $currentRows = $currentAll->whereIn('status', $registrantStates);

        $priorBySchool = $priorRows->groupBy('school_id');
        $currentBySchool = $currentRows->groupBy('school_id');

        $retainedIds = $priorBySchool->keys()->intersect($currentBySchool->keys());
        $notBackIds = $priorBySchool->keys()->diff($currentBySchool->keys());
        $newIds = $currentBySchool->keys()->diff($priorBySchool->keys());

        $schoolNames = School::whereIn('id', $priorBySchool->keys()->merge($currentBySchool->keys())->unique())->pluck('name', 'id');
        $teacherNames = $this->teacherNames($priorRows->pluck('teacher_id')->merge($currentRows->pluck('teacher_id'))->unique());

        $unregisteredBySchool = $currentAll
            ->whereIn('status', [CandidateStatus::Eligible->value, CandidateStatus::Pending->value])
            ->countBy('school_id');

        $teacherChanged = [];
        $fewer = [];
        foreach ($retainedIds as $schoolId) {
            $before = $priorBySchool[$schoolId];
            $after = $currentBySchool[$schoolId];
            $was = $before->pluck('teacher_id')->unique()->sort()->values();
            $now_ = $after->pluck('teacher_id')->unique()->sort()->values();

            if ($was->all() !== $now_->all()) {
                $teacherChanged[] = [
                    'name' => (string) ($schoolNames[$schoolId] ?? 'Unknown school'),
                    'priorStudents' => $before->count(),
                    'currentStudents' => $after->count(),
                    'was' => $this->names($was, $teacherNames),
                    'now' => $this->names($now_, $teacherNames),
                ];
            }

            if ($after->count() < $before->count()) {
                $fewer[] = [
                    'name' => (string) ($schoolNames[$schoolId] ?? 'Unknown school'),
                    'priorStudents' => $before->count(),
                    'currentStudents' => $after->count(),
                    'change' => $after->count() - $before->count(),
                    'unregistered' => (int) ($unregisteredBySchool[$schoolId] ?? 0),
                ];
            }
        }

        usort($teacherChanged, fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        usort($fewer, fn (array $a, array $b): int => [$a['change'], $a['name']] <=> [$b['change'], $b['name']]);

        $retainedPrior = $retainedIds->sum(fn (int $id): int => $priorBySchool[$id]->count());
        $retainedCurrent = $retainedIds->sum(fn (int $id): int => $currentBySchool[$id]->count());

        return [
            'prior' => $prior,
            'final' => $this->deadlinePassed($version, $now),
            'schools' => [
                'prior' => $priorBySchool->count(),
                'retained' => $retainedIds->count(),
                'retainedPercent' => (int) round(100 * $retainedIds->count() / $priorBySchool->count()),
                'notBack' => $notBackIds->count(),
                'new' => $newIds->count(),
            ],
            'students' => [
                'prior' => $priorRows->count(),
                'current' => $currentRows->count(),
                'retainedChange' => $retainedCurrent - $retainedPrior,
                'notBackLost' => -$notBackIds->sum(fn (int $id): int => $priorBySchool[$id]->count()),
                'newGained' => $newIds->sum(fn (int $id): int => $currentBySchool[$id]->count()),
            ],
            'teachers' => $this->newTeachers($version, $currentRows, $registrantStates),
            'notBack' => $this->notBack($version, $notBackIds, $priorBySchool, $schoolNames, $teacherNames, $currentAll),
            'teacherChanged' => $teacherChanged,
            'fewer' => $fewer,
        ];
    }

    /**
     * The same event's Version for the class year before this one.
     */
    private function priorVersion(Version $version): ?Version
    {
        return Version::where('event_id', $version->event_id)
            ->where('senior_class_of', '<', $version->senior_class_of)
            ->orderByDesc('senior_class_of')
            ->first();
    }

    /**
     * Before the Postmark Deadline a school that isn't back may still come
     * back; after it, the school is lost for this Version.
     */
    private function deadlinePassed(Version $version, Carbon $now): bool
    {
        $deadlineAt = VersionDate::where('version_id', $version->id)
            ->where('date_type', VersionDateType::PostmarkDeadline)
            ->value('start_at');

        return $deadlineAt !== null && Carbon::parse($deadlineAt)->lte($now);
    }

    /**
     * New to the event: no participating students in any earlier Version of
     * this event. New to TDR: no students on any Version, of any event, for
     * an earlier class year.
     *
     * @param  Collection<int, stdClass>  $currentRows
     * @param  list<string>  $registrantStates
     * @return array{current: int, newToEvent: int, newToEventStudents: int, newToTdr: int}
     */
    private function newTeachers(Version $version, Collection $currentRows, array $registrantStates): array
    {
        $studentsByTeacher = $currentRows->countBy('teacher_id');
        $teacherIds = $studentsByTeacher->keys();

        $returning = Candidate::whereIn('teacher_id', $teacherIds)
            ->whereIn('status', $registrantStates)
            ->whereIn('version_id', Version::where('event_id', $version->event_id)
                ->where('senior_class_of', '<', $version->senior_class_of)
                ->select('id'))
            ->distinct()
            ->pluck('teacher_id')
            ->flip();

        // By class year, not created_at: Versions imported from the legacy
        // site all carry the import date.
        $seenBefore = Candidate::whereIn('teacher_id', $teacherIds)
            ->whereIn('version_id', Version::where('senior_class_of', '<', $version->senior_class_of)->select('id'))
            ->distinct()
            ->pluck('teacher_id')
            ->flip();

        $newToEvent = $teacherIds->reject(fn (int $id): bool => $returning->has($id));

        return [
            'current' => $teacherIds->count(),
            'newToEvent' => $newToEvent->count(),
            'newToEventStudents' => (int) $newToEvent->sum(fn (int $id): int => $studentsByTeacher[$id]),
            'newToTdr' => $teacherIds->reject(fn (int $id): bool => $seenBefore->has($id))->count(),
        ];
    }

    /**
     * Each school that isn't back, with whoever is invited from it now and
     * the students they have short of registered. No invited teacher means
     * the school can't come back unless someone there is invited.
     *
     * @param  Collection<int, int>  $notBackIds
     * @param  Collection<int|string, Collection<int, stdClass>>  $priorBySchool
     * @param  Collection<int, string>  $schoolNames
     * @param  array<int, array{name: string, email: string}>  $teacherNames
     * @param  Collection<int, stdClass>  $currentAll
     * @return list<array{name: string, priorStudents: int, priorTeachers: list<string>, invited: list<array{name: string, email: string, eligible: int, pending: int}>}>
     */
    private function notBack(Version $version, Collection $notBackIds, Collection $priorBySchool, Collection $schoolNames, array $teacherNames, Collection $currentAll): array
    {
        if ($notBackIds->isEmpty()) {
            return [];
        }

        $invitedBySchool = [];
        foreach ($this->invitationEligibility->invitedTeachers($version) as $teacher) {
            foreach ($teacher->schools as $school) {
                if ($notBackIds->contains($school->id)) {
                    $invitedBySchool[$school->id][] = $teacher;
                }
            }
        }

        $rows = $notBackIds->map(function (int $schoolId) use ($priorBySchool, $schoolNames, $teacherNames, $invitedBySchool, $currentAll): array {
            $invited = array_map(function (Teacher $teacher) use ($schoolId, $currentAll): array {
                $theirs = $currentAll->where('school_id', $schoolId)->where('teacher_id', $teacher->id);

                return [
                    'name' => $teacher->user->name,
                    'email' => $teacher->user->email,
                    'eligible' => $theirs->where('status', CandidateStatus::Eligible->value)->count(),
                    'pending' => $theirs->where('status', CandidateStatus::Pending->value)->count(),
                ];
            }, $invitedBySchool[$schoolId] ?? []);

            return [
                'name' => (string) ($schoolNames[$schoolId] ?? 'Unknown school'),
                'priorStudents' => $priorBySchool[$schoolId]->count(),
                'priorTeachers' => $this->names($priorBySchool[$schoolId]->pluck('teacher_id')->unique()->sort()->values(), $teacherNames),
                'invited' => $invited,
            ];
        })->all();

        usort($rows, fn (array $a, array $b): int => [$b['priorStudents'], $a['name']] <=> [$a['priorStudents'], $b['name']]);

        return $rows;
    }

    /**
     * @param  Collection<int, int>  $teacherIds
     * @return array<int, array{name: string, email: string}>
     */
    private function teacherNames(Collection $teacherIds): array
    {
        return Teacher::whereIn('id', $teacherIds)
            ->with('user')
            ->get()
            ->mapWithKeys(fn (Teacher $teacher): array => [$teacher->id => [
                'name' => $teacher->user->name,
                'email' => $teacher->user->email,
            ]])
            ->all();
    }

    /**
     * @param  Collection<int, int>  $teacherIds
     * @param  array<int, array{name: string, email: string}>  $teacherNames
     * @return list<string>
     */
    private function names(Collection $teacherIds, array $teacherNames): array
    {
        return $teacherIds->map(fn (int $id): string => $teacherNames[$id]['name'] ?? 'Unknown teacher')->sort()->values()->all();
    }
}
