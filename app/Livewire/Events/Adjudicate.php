<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\AdjudicationEventType;
use App\Enums\AuditionType;
use App\Enums\VersionDateType;
use App\Models\AdjudicationEvent;
use App\Models\Candidate;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\ScoreCategory;
use App\Models\Version;
use App\Models\VersionDate;
use App\Services\AdjudicationService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Adjudicate extends Component
{
    public Version $version;

    public RoomJudge $roomJudge;

    #[Url]
    public ?int $selectedCandidateId = null;

    /**
     * [score_factor_id => score]. Only ever populated for factors the judge
     * has explicitly chosen (or previously saved) — never defaulted.
     *
     * @var array<int, int|string>
     */
    public array $scores = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles, AdjudicationService $adjudication): void
    {
        abort_unless($roles->canAdjudicate(Auth::user(), $version), 403);

        $this->version = $version;
        $this->roomJudge = RoomJudge::where('version_id', $version->id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        if ($this->selectedCandidateId !== null) {
            $candidate = $adjudication->candidatesForRoom($this->roomJudge->room)->firstWhere('id', $this->selectedCandidateId);

            if ($candidate === null) {
                $this->selectedCandidateId = null;
            } else {
                $this->scores = $adjudication->existingScoresFor($this->roomJudge, $candidate);
                $this->logCandidateOpened($candidate, $adjudication);
            }
        }
    }

    public function selectCandidate(int $candidateId, AdjudicationService $adjudication): void
    {
        $candidate = $adjudication->candidatesForRoom($this->roomJudge->room)->firstWhere('id', $candidateId);

        abort_unless($candidate !== null, 404);

        $this->selectedCandidateId = $candidateId;
        $this->resetValidation();
        $this->scores = $adjudication->existingScoresFor($this->roomJudge, $candidate);
        $this->logCandidateOpened($candidate, $adjudication);
    }

    public function save(AdjudicationService $adjudication): void
    {
        $candidate = $adjudication->candidatesForRoom($this->roomJudge->room)->firstWhere('id', $this->selectedCandidateId);

        abort_unless($candidate !== null, 404);

        $factors = $adjudication->roomRubric($this->roomJudge->room)
            ->flatMap(fn (ScoreCategory $category) => $category->scoreFactors);

        if ($factors->isEmpty()) {
            Flux::toast(text: 'This room has no scoring rubric yet, so there is nothing to save.', variant: 'danger');

            return;
        }

        $rules = [];
        foreach ($factors as $factor) {
            $rules["scores.{$factor->id}"] = ['required', Rule::in($adjudication->optionsForFactor($factor))];
        }

        $this->validate($rules, [
            'scores.*.required' => 'Select a score for every factor before saving.',
            'scores.*.in' => 'Select a valid score for every factor before saving.',
        ]);

        $adjudication->saveScores($this->roomJudge, $candidate, $this->version, $this->scores);

        Flux::toast('Scores saved.');
    }

    /**
     * Advances to the next candidate this judge hasn't fully scored yet.
     * Not part of the requirements doc — a low-cost convenience on top of
     * it, not required functionality.
     */
    public function nextCandidate(AdjudicationService $adjudication): void
    {
        $room = $this->roomJudge->room;
        $candidates = $adjudication->candidatesForRoom($room);
        $factorCount = $adjudication->factorCount($room);
        $completion = $adjudication->judgeCompletionFor($this->roomJudge, $candidates->pluck('id'), $factorCount);

        $currentIndex = $this->selectedCandidateId !== null
            ? $candidates->search(fn (Candidate $candidate): bool => $candidate->id === $this->selectedCandidateId)
            : -1;

        $startAt = $currentIndex === false ? 0 : $currentIndex + 1;

        for ($i = $startAt; $i < $candidates->count(); $i++) {
            $candidate = $candidates[$i];

            if (! ($completion[$candidate->id] ?? false)) {
                $this->selectCandidate($candidate->id, $adjudication);

                return;
            }
        }
    }

    /**
     * Playback reported by the browser's <audio> element. Renderless: a
     * re-render here would only add load, and the page must not change
     * under a judge who is listening.
     */
    #[Renderless]
    public function trackRecording(int $recordingId, string $event, float $positionSeconds): void
    {
        $type = AdjudicationEventType::tryFrom($event);
        $recording = $this->selectedRecording($recordingId);

        if ($type === null || ! in_array($type, AdjudicationEventType::playbackEvents(), true) || $recording === null) {
            return;
        }

        AdjudicationEvent::create([
            'version_id' => $this->version->id,
            'room_judge_id' => $this->roomJudge->id,
            'candidate_id' => $recording->candidate_id,
            'recording_id' => $recording->id,
            'event' => $type,
            'position_seconds' => $this->wholeSeconds($positionSeconds),
        ]);
    }

    /**
     * The recording's length, as the judge's browser read it from the
     * file's metadata. Stored once; a recording doesn't change after upload.
     */
    #[Renderless]
    public function recordDuration(int $recordingId, float $durationSeconds): void
    {
        $recording = $this->selectedRecording($recordingId);
        $seconds = $this->wholeSeconds($durationSeconds);

        if ($recording === null || $recording->duration_seconds !== null || $seconds === null || $seconds === 0) {
            return;
        }

        $recording->update(['duration_seconds' => $seconds]);
    }

    /**
     * roster_position is the candidate's 1-based place in this judge's
     * roster (the same order every judge in the Room sees), so analytics can
     * tell working-in-order from jumping around.
     */
    private function logCandidateOpened(Candidate $candidate, AdjudicationService $adjudication): void
    {
        $position = $adjudication->candidatesForRoom($this->roomJudge->room)
            ->search(fn (Candidate $rosterCandidate): bool => $rosterCandidate->id === $candidate->id);

        AdjudicationEvent::create([
            'version_id' => $this->version->id,
            'room_judge_id' => $this->roomJudge->id,
            'candidate_id' => $candidate->id,
            'event' => AdjudicationEventType::CandidateOpened,
            'roster_position' => $position === false ? null : $position + 1,
        ]);
    }

    /**
     * Only an approved recording of the candidate on screen — the browser
     * can't log against anything else.
     */
    private function selectedRecording(int $recordingId): ?Recording
    {
        if ($this->selectedCandidateId === null) {
            return null;
        }

        return Recording::where('id', $recordingId)
            ->where('version_id', $this->version->id)
            ->where('candidate_id', $this->selectedCandidateId)
            ->whereNotNull('approved_at')
            ->first();
    }

    /**
     * Browsers report NaN/Infinity while a stream's length is unknown, and
     * nothing over three hours is an audition recording.
     */
    private function wholeSeconds(float $seconds): ?int
    {
        if (! is_finite($seconds) || $seconds < 0 || $seconds > 10800) {
            return null;
        }

        return (int) round($seconds);
    }

    public function render(AdjudicationService $adjudication): View
    {
        $room = $this->roomJudge->room;

        $candidates = $adjudication->candidatesForRoom($room);
        $candidateIds = $candidates->pluck('id');
        $factorCount = $adjudication->factorCount($room);

        $statuses = $adjudication->candidateStatuses($room, $candidateIds);
        $tolerances = $adjudication->candidateTolerances($room, $candidateIds);
        $judgeCompletion = $adjudication->judgeCompletionFor($this->roomJudge, $candidateIds, $factorCount);

        $counts = ['completed' => 0, 'partial' => 0, 'none' => 0, 'error' => 0];

        foreach ($statuses as $status) {
            $counts[$status]++;
        }

        $total = max($candidates->count(), 1);
        $percentages = array_map(fn (int $count): float => round($count / $total * 100, 1), $counts);

        $selectedCandidate = $this->selectedCandidateId !== null
            ? $candidates->firstWhere('id', $this->selectedCandidateId)
            : null;

        // Room Scores only ever appears once this judge has saved their own
        // scores for this candidate — checked against the database, not the
        // live-bound $this->scores buffer, so an unsaved in-progress
        // selection never trips it early.
        $judgeHasScoredSelected = $selectedCandidate !== null
            && $adjudication->existingScoresFor($this->roomJudge, $selectedCandidate) !== [];

        $roomScores = $judgeHasScoredSelected
            ? $adjudication->scoresForCandidate($room, $selectedCandidate)
            : collect();

        $adjudicationWindow = VersionDate::where('version_id', $this->version->id)
            ->where('date_type', VersionDateType::Adjudication)
            ->first();

        $rubric = $adjudication->roomRubric($room);

        $factorOptions = $rubric
            ->flatMap(fn (ScoreCategory $category) => $category->scoreFactors)
            ->mapWithKeys(fn ($factor) => [$factor->id => $adjudication->optionsForFactor($factor)]);

        $showRecordings = $this->version->getRawOriginal('audition_type') === AuditionType::Remote->value;

        $recordings = $showRecordings && $selectedCandidate !== null
            ? $adjudication->recordingsForCandidate($room, $selectedCandidate)
            : collect();

        return view('livewire.events.adjudicate', [
            'room' => $room,
            'candidates' => $candidates,
            'statuses' => $statuses,
            'tolerances' => $tolerances,
            'judgeCompletion' => $judgeCompletion,
            'counts' => $counts,
            'percentages' => $percentages,
            'roomJudgesOrdered' => $adjudication->roomJudgesOrdered($room),
            'rubric' => $rubric,
            'factorOptions' => $factorOptions,
            'selectedCandidate' => $selectedCandidate,
            'roomScores' => $roomScores,
            'showRecordings' => $showRecordings,
            'recordings' => $recordings,
            'adjudicationWindow' => $adjudicationWindow,
            'hasRubric' => $factorCount > 0,
        ]);
    }
}
