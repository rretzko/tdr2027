<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Models\Candidate;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Services\RegistrationHistoryService;
use App\Services\RegistrationHistoryTextExporter;
use App\Services\VersionRoleAssignmentService;
use App\Support\RegistrationHistory\RegistrationHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Registration History tab on Web Registration. Open to everyone who can
 * reach that page (VersionRoleAssignmentService::canViewRegistrationHistory());
 * Co-Registration Managers are limited to their counties.
 */
class VersionRegistrationHistory extends Component
{
    private const MIN_SEARCH = 2;

    private const MAX_RESULTS = 25;

    #[Locked]
    public int $versionId;

    public string $teacherSearch = '';

    public string $studentSearch = '';

    #[Locked]
    public ?int $teacherId = null;

    #[Locked]
    public ?int $candidateId = null;

    public function mount(Version $version): void
    {
        $this->versionId = $version->id;
        $this->authorizedVersion();
    }

    public function updatedTeacherSearch(): void
    {
        $this->studentSearch = '';
        $this->clearSelection();
    }

    public function updatedStudentSearch(): void
    {
        $this->teacherSearch = '';
        $this->clearSelection();
    }

    public function selectTeacher(int $teacherId, RegistrationHistoryService $service): void
    {
        $version = $this->authorizedVersion();
        abort_unless($service->teachersQuery($version, $this->countyIds($version))->whereKey($teacherId)->exists(), 404);

        $this->clearSelection();
        $this->teacherId = $teacherId;
    }

    public function selectStudent(int $candidateId, RegistrationHistoryService $service): void
    {
        $version = $this->authorizedVersion();
        abort_unless($service->candidatesQuery($version, $this->countyIds($version))->whereKey($candidateId)->exists(), 404);

        $this->clearSelection();
        $this->candidateId = $candidateId;
    }

    public function clearSelection(): void
    {
        $this->teacherId = null;
        $this->candidateId = null;
    }

    public function download(RegistrationHistoryService $service, RegistrationHistoryTextExporter $exporter): StreamedResponse
    {
        $history = $this->history($this->authorizedVersion(), $service);
        abort_if($history === null, 404);

        $now = now();
        $text = $exporter->render($history, $now);

        return response()->streamDownload(
            function () use ($text): void {
                echo $text;
            },
            $exporter->filename($history, $now),
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    public function render(RegistrationHistoryService $service): View
    {
        $version = $this->authorizedVersion();
        $history = $this->history($version, $service);
        $candidates = $history === null ? $this->candidates($version, $service) : null;

        return view('livewire.events.version-registration-history', [
            'history' => $history,
            'teachers' => $history === null ? $this->teachers($version, $service) : null,
            'candidates' => $candidates,
            'statuses' => $candidates?->mapWithKeys(fn (Candidate $c): array => [$c->id => $service->displayStatus($c, $version)])->all() ?? [],
            'maxResults' => self::MAX_RESULTS,
            'notes' => RegistrationHistoryTextExporter::NOTES,
        ]);
    }

    /** Null until something is selected. Re-scoped on every request. */
    private function history(Version $version, RegistrationHistoryService $service): ?RegistrationHistory
    {
        $countyIds = $this->countyIds($version);

        if ($this->candidateId !== null) {
            $candidate = $service->candidatesQuery($version, $countyIds)->whereKey($this->candidateId)->first();
            abort_if($candidate === null, 404);

            return $service->forCandidate($candidate->setRelation('version', $version));
        }

        if ($this->teacherId !== null) {
            $teacher = $service->teachersQuery($version, $countyIds)->whereKey($this->teacherId)->with('user')->first();
            abort_if($teacher === null, 404);

            return $service->forTeacher($version, $teacher, $countyIds);
        }

        return null;
    }

    /**
     * @return Collection<int, Teacher>|null null = nothing searched yet
     */
    private function teachers(Version $version, RegistrationHistoryService $service): ?Collection
    {
        if (mb_strlen(trim($this->teacherSearch)) < self::MIN_SEARCH) {
            return null;
        }

        return $service->teachersQuery($version, $this->countyIds($version))
            ->whereHas('user', fn ($q) => $service->whereNameMatches($q, $this->teacherSearch))
            ->with([
                'user',
                'schools' => fn ($q) => $q->wherePivot('is_active', true)->whereNotNull('school_teacher.verified_at')->orderBy('schools.name'),
            ])
            ->limit(self::MAX_RESULTS)
            ->get()
            ->sortBy(fn (Teacher $teacher): string => $teacher->user->sort_name)
            ->values();
    }

    /**
     * @return Collection<int, Candidate>|null null = nothing searched yet
     */
    private function candidates(Version $version, RegistrationHistoryService $service): ?Collection
    {
        if (mb_strlen(trim($this->studentSearch)) < self::MIN_SEARCH) {
            return null;
        }

        return $service->candidatesQuery($version, $this->countyIds($version))
            ->whereHas('student.user', fn ($q) => $service->whereNameMatches($q, $this->studentSearch))
            ->with(['student.user', 'school', 'teacher.user'])
            ->limit(self::MAX_RESULTS)
            ->get()
            ->sortBy(fn (Candidate $candidate): string => $candidate->student->user->sort_name)
            ->values();
    }

    private function authorizedVersion(): Version
    {
        $version = Version::query()->with('event')->find($this->versionId);
        $user = Auth::user();

        abort_unless(
            $version !== null && $user instanceof User && app(VersionRoleAssignmentService::class)->canViewRegistrationHistory($user, $version),
            403,
        );

        return $version;
    }

    /**
     * @return list<int>|null
     */
    private function countyIds(Version $version): ?array
    {
        $user = Auth::user();

        return $user instanceof User
            ? app(VersionRoleAssignmentService::class)->registrationHistoryCountyIds($user, $version)
            : [];
    }
}
