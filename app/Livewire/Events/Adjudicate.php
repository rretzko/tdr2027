<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\AdjudicationEventType;
use App\Enums\AuditionType;
use App\Enums\JudgeSurvey\JudgeAgain;
use App\Enums\JudgeSurvey\JudgeCheckpoint;
use App\Enums\JudgeSurvey\JudgeDeviceType;
use App\Enums\JudgeSurvey\JudgeFocus;
use App\Enums\JudgeSurvey\JudgeObstacle;
use App\Enums\JudgeSurvey\ListeningDevice;
use App\Enums\VersionDateType;
use App\Models\AdjudicationEvent;
use App\Models\Candidate;
use App\Models\JudgeSurveyResponse;
use App\Models\Recording;
use App\Models\RoomJudge;
use App\Models\ScoreCategory;
use App\Models\Version;
use App\Models\VersionDate;
use App\Services\AdjudicationService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Arr;
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

    /**
     * Detected once per page load by reportDevice(); stored on any
     * checkpoint row so the device mix is known without asking.
     */
    public ?string $deviceType = null;

    public ?int $screenWidth = null;

    /** The open checkpoint popup (a JudgeCheckpoint value), if any. */
    public ?string $checkpoint = null;

    public bool $showCheckpoint = false;

    public string $checkpointMessage = '';

    public string $checkpointTip = '';

    public ?string $focus = null;

    /** @var list<string> */
    public array $obstacles = [];

    public ?string $listeningDevice = null;

    public ?int $overallRating = null;

    public ?string $judgeAgain = null;

    public string $surveyComment = '';

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

        $candidates = $adjudication->candidatesForRoom($this->roomJudge->room);
        $factorCount = $factors->count();
        $completionBefore = $adjudication->judgeCompletionFor($this->roomJudge, $candidates->pluck('id'), $factorCount);

        $adjudication->saveScores($this->roomJudge, $candidate, $this->version, $this->scores);

        // Re-saving a candidate already scored (a correction) is never a
        // milestone, so only a first full score sheet moves the count. The
        // save above always writes every factor (validated), so this
        // candidate is now complete.
        if (! ($completionBefore[$candidate->id] ?? false)) {
            $completed = count(array_filter($completionBefore)) + 1;

            if ($completed === $candidates->count()) {
                Flux::toast(
                    text: JudgeCheckpoint::fill(JudgeCheckpoint::completionMessage(), $this->messageValues($completed, $candidates->count())),
                    heading: 'Scores saved.',
                    duration: 10000,
                    variant: 'success',
                );

                return;
            }

            if ($this->openCheckpoint($completed, $candidates->count())) {
                return;
            }
        }

        Flux::toast('Scores saved.');
    }

    /**
     * The browser's screen, reported once on page load. Renderless: nothing
     * on screen depends on it.
     */
    #[Renderless]
    public function reportDevice(int $screenWidth, int $screenHeight, bool $coarsePointer): void
    {
        if ($screenWidth < 1 || $screenHeight < 1 || $screenWidth > 20000 || $screenHeight > 20000) {
            return;
        }

        $this->deviceType = JudgeDeviceType::detect($screenWidth, $screenHeight, $coarsePointer)->value;
        $this->screenWidth = $screenWidth;
    }

    public function submitCheckpoint(): void
    {
        $response = $this->openResponse();

        if ($response === null) {
            $this->closeCheckpoint();

            return;
        }

        /** @var array{focus: ?string, obstacles: list<string>, listeningDevice: ?string, overallRating: ?int, judgeAgain: ?string, surveyComment: ?string} $validated */
        $validated = $this->validate([
            'focus' => ['nullable', Rule::enum(JudgeFocus::class)],
            'obstacles' => ['array'],
            'obstacles.*' => [Rule::enum(JudgeObstacle::class)],
            'listeningDevice' => ['nullable', Rule::enum(ListeningDevice::class)],
            'overallRating' => ['nullable', 'integer', 'between:1,5'],
            'judgeAgain' => ['nullable', Rule::enum(JudgeAgain::class)],
            'surveyComment' => ['nullable', 'string', 'max:2000'],
        ]);

        $checkpoint = JudgeCheckpoint::from((string) $response->getRawOriginal('checkpoint'));
        $obstacles = array_values(array_unique($validated['obstacles']));
        $comment = trim((string) $validated['surveyComment']);

        // Each checkpoint asks its own question; anything else is ignored.
        $answers = [
            'focus' => $validated['focus'],
            'obstacles' => $checkpoint === JudgeCheckpoint::First && $obstacles !== [] ? $obstacles : null,
            'listening_device' => $checkpoint === JudgeCheckpoint::Second ? $validated['listeningDevice'] : null,
            'overall_rating' => $checkpoint === JudgeCheckpoint::Final ? $validated['overallRating'] : null,
            'judge_again' => $checkpoint === JudgeCheckpoint::Final ? $validated['judgeAgain'] : null,
            'comment' => $comment !== '' ? $comment : null,
        ];

        // Submitting a blank form is a dismissal, not a response.
        if (array_filter($answers, fn (mixed $answer): bool => $answer !== null) === []) {
            $this->dismissCheckpoint();

            return;
        }

        $response->update([...$answers, 'submitted_at' => now()]);

        $this->closeCheckpoint();
        Flux::toast('Thank you. Back to the music.');
    }

    /**
     * "No thanks", the close button or Escape. A dismissed checkpoint is
     * never shown again; an already-submitted one is left alone.
     */
    public function dismissCheckpoint(): void
    {
        $response = $this->openResponse();

        if ($response !== null && $response->submitted_at === null && $response->dismissed_at === null) {
            $response->update(['dismissed_at' => now()]);
        }

        $this->closeCheckpoint();
    }

    /**
     * Records the checkpoint as shown (so it's shown once, even if the
     * judge reloads or closes the tab) and opens the popup.
     */
    private function openCheckpoint(int $completed, int $total): bool
    {
        if (! $this->version->judge_survey) {
            return false;
        }

        $checkpoint = JudgeCheckpoint::reachedAt($completed, $total);

        if ($checkpoint === null) {
            return false;
        }

        $response = JudgeSurveyResponse::firstOrCreate(
            ['room_judge_id' => $this->roomJudge->id, 'checkpoint' => $checkpoint->value],
            [
                'version_id' => $this->version->id,
                'completed_count' => $completed,
                'roster_count' => $total,
                'device_type' => $this->deviceType,
                'screen_width' => $this->screenWidth,
            ],
        );

        if (! $response->wasRecentlyCreated) {
            return false;
        }

        $values = $this->messageValues($completed, $total);

        $this->reset('focus', 'obstacles', 'listeningDevice', 'overallRating', 'judgeAgain', 'surveyComment');
        $this->resetValidation();
        $this->checkpoint = $checkpoint->value;
        $this->checkpointMessage = JudgeCheckpoint::fill(Arr::random($checkpoint->messages()), $values);
        $this->checkpointTip = Arr::random($checkpoint->tips());
        $this->showCheckpoint = true;

        return true;
    }

    private function judgeFirstName(): string
    {
        $user = $this->roomJudge->user;
        $first = trim((string) $user->first_name);

        return $first !== '' ? $first : $user->name;
    }

    private function openResponse(): ?JudgeSurveyResponse
    {
        $checkpoint = $this->checkpoint !== null ? JudgeCheckpoint::tryFrom($this->checkpoint) : null;

        if ($checkpoint === null) {
            return null;
        }

        return JudgeSurveyResponse::where('room_judge_id', $this->roomJudge->id)
            ->where('checkpoint', $checkpoint->value)
            ->first();
    }

    private function closeCheckpoint(): void
    {
        $this->checkpoint = null;
        $this->showCheckpoint = false;
    }

    /**
     * @return array<string, string|int>
     */
    private function messageValues(int $completed, int $total): array
    {
        return [
            'name' => $this->judgeFirstName(),
            'done' => $completed,
            'total' => $total,
            'remaining' => $total - $completed,
            'room' => $this->roomJudge->room->name,
        ];
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
            'checkpointCase' => $this->checkpoint !== null ? JudgeCheckpoint::tryFrom($this->checkpoint) : null,
        ]);
    }
}
