<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\CandidateUploadStatus;
use App\Exceptions\VoicePartChangeRefusedException;
use App\Models\Candidate;
use App\Models\Score;
use App\Models\Version;
use App\Models\VoicePart;
use App\Support\VoicePartAuditRow;
use Illuminate\Support\Collection;

/**
 * Data + the one write behind the Voice Part ↔ Recording audit
 * (docs/plans/voice-part-recording-audit.md): Candidates with at least one
 * teacher-approved recording, each alongside the voice parts its grade is
 * offered, so a reviewer can check the voice part by ear and correct it.
 *
 * Deliberately auth-free — the Founder page authorizes today, and an Event
 * Manager / Registration Manager page can reuse this unchanged later.
 *
 * changeVoicePart() is the non-destructive counterpart to
 * AdjudicationService::changeVoicePart() (Tab Room), which wipes Scores and
 * non-matching Recordings: this one refuses outright once a Candidate has
 * Scores, and otherwise only changes voice_part_id and recalculates status.
 */
class VoicePartAuditService
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_ALL = 'all';

    /** @var array<string, Collection<int, VoicePart>> */
    private array $offeredByGrade = [];

    public function __construct(private readonly CandidateService $candidates) {}

    /**
     * @param  array{status?: string, voicePartId?: ?int, mismatchOnly?: bool, search?: string, sort?: string, direction?: string}  $filters
     * @return Collection<int, VoicePartAuditRow>
     */
    public function rows(Version $version, array $filters = []): Collection
    {
        $status = $filters['status'] ?? self::STATUS_ACTIVE;
        $search = trim($filters['search'] ?? '');

        $query = Candidate::query()
            ->where('version_id', $version->id)
            ->whereHas('uploadFiles', fn ($q) => $q->where('status', CandidateUploadStatus::Approved->value))
            ->with([
                'student.user', 'student.schools', 'teacher.user', 'school', 'voicePart',
                'uploadFiles' => fn ($q) => $q->where('status', CandidateUploadStatus::Approved->value)->with('versionUploadFile'),
            ]);

        if ($status === self::STATUS_ACTIVE) {
            $query->whereIn('status', array_map(fn (CandidateStatus $s): string => $s->value, CandidateStatus::registrationStates()));
        } elseif ($status !== self::STATUS_ALL && CandidateStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        if (($filters['voicePartId'] ?? null) !== null) {
            $query->where('voice_part_id', $filters['voicePartId']);
        }

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(fn ($q) => $q
                ->whereHas('student.user', fn ($u) => $u->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like]))
                ->orWhereHas('teacher.user', fn ($u) => $u->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like])));
        }

        $rows = $query->get()->toBase()->map(function (Candidate $candidate) use ($version): VoicePartAuditRow {
            $grade = $candidate->student->grade;
            $offered = $this->offeredVoiceParts($version, $grade);

            return new VoicePartAuditRow($candidate, $grade, $offered, $offered->contains('id', $candidate->voice_part_id));
        });

        if ($filters['mismatchOnly'] ?? false) {
            $rows = $rows->reject(fn (VoicePartAuditRow $row): bool => $row->isOffered);
        }

        return $this->sort($rows, $filters['sort'] ?? 'voice_part', $filters['direction'] ?? 'asc')->values();
    }

    /**
     * Voice parts offered to a grade by this Version's Ensembles, memoized
     * per grade for the life of the service.
     *
     * @return Collection<int, VoicePart>
     */
    public function offeredVoiceParts(Version $version, ?int $grade): Collection
    {
        return $this->offeredByGrade["{$version->id}-{$grade}"] ??= $version->availableVoicePartsForGrade($grade);
    }

    /**
     * @param  list<array{label: string, check: \Closure(Candidate): bool}>  $checklistDefs  from HasCandidateChecklist
     * @return array{from: ?VoicePart, to: VoicePart}
     *
     * @throws VoicePartChangeRefusedException
     */
    public function changeVoicePart(Candidate $candidate, int $voicePartId, array $checklistDefs): array
    {
        $candidate->loadMissing(['version', 'student', 'voicePart']);
        $offered = $this->offeredVoiceParts($candidate->version, $candidate->student->grade);
        $to = $offered->firstWhere('id', $voicePartId);

        if (! $to instanceof VoicePart) {
            $gradeLabel = $candidate->student->grade !== null ? "grade {$candidate->student->grade}" : 'this student';

            throw new VoicePartChangeRefusedException("That voice part isn't offered for {$gradeLabel}.");
        }

        if (Score::where('candidate_id', $candidate->id)->exists()) {
            throw new VoicePartChangeRefusedException(
                'This candidate already has scores. Use Tab Room → Add/Edit Scores → Change Voice Part instead.'
            );
        }

        $from = $candidate->voicePart;

        if ($from?->id === $to->id) {
            return ['from' => $from, 'to' => $to];
        }

        $candidate->update(['voice_part_id' => $to->id]);
        $this->candidates->recalculateStatus($candidate->refresh(), $checklistDefs);

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Comparator-based (not sortBy() with an array of 1-arg callbacks, which
     * silently misorders — see the PHPStan-quirks memory).
     *
     * @param  Collection<int, VoicePartAuditRow>  $rows
     * @return Collection<int, VoicePartAuditRow>
     */
    private function sort(Collection $rows, string $sort, string $direction): Collection
    {
        $studentKey = fn (Candidate $c): string => mb_strtolower($c->student->user->last_name.' '.$c->student->user->first_name);
        $teacherKey = fn (Candidate $c): string => mb_strtolower(($c->teacher->user->last_name ?? '').' '.($c->teacher->user->first_name ?? ''));
        $voicePartKey = fn (Candidate $c): int => $c->voicePart->sort_order ?? PHP_INT_MAX;
        $sign = $direction === 'desc' ? -1 : 1;

        return $rows->sort(function (VoicePartAuditRow $a, VoicePartAuditRow $b) use ($sort, $sign, $studentKey, $teacherKey, $voicePartKey): int {
            $x = $a->candidate;
            $y = $b->candidate;

            $primary = match ($sort) {
                'student' => $studentKey($x) <=> $studentKey($y),
                'teacher' => $teacherKey($x) <=> $teacherKey($y),
                default => $voicePartKey($x) <=> $voicePartKey($y),
            };

            return $sign * $primary ?: ($studentKey($x) <=> $studentKey($y)) ?: ($x->id <=> $y->id);
        });
    }
}
