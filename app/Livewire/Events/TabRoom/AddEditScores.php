<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom;

use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\VoicePartRecordingDecision;
use App\Models\Candidate;
use App\Models\RoomJudge;
use App\Models\ScoreCategory;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tab Room Manager's Add/Edit Scores sub-module (Tab Room Module.docx):
 * search a Candidate by id or last name, then create/update any judge's
 * scores on that Candidate's behalf. Structurally mirrors Adjudicate.php,
 * but candidate/room/judge are all manager-selected rather than implied by
 * the logged-in judge's own RoomJudge row.
 *
 * Any Candidate status is findable and viewable. Editing (save() and
 * changeVoicePart()) is blocked only once the Version is Closed — the
 * manager must reopen it via CloseAudition first. A Candidate already
 * resolved by Ensemble Cut-offs stays editable, with a warning that the
 * cut-offs need re-evaluating.
 */
#[Layout('components.layouts.app')]
class AddEditScores extends Component
{
    public Version $version;

    public string $candidateIdSearch = '';

    public string $lastNameSearch = '';

    /**
     * @var list<array{id: int, name: string, voicePart: string}>
     */
    public array $matches = [];

    #[Url]
    public ?int $selectedCandidateId = null;

    public ?int $selectedRoomId = null;

    public ?int $selectedJudgeId = null;

    public string $newVoicePartId = '';

    public string $recording_decision = '';

    /**
     * [score_factor_id => score] — only ever populated for factors the
     * selected judge has explicitly chosen or previously saved.
     *
     * @var array<int, int|string>
     */
    public array $scores = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles, AdjudicationService $adjudication): void
    {
        abort_unless($roles->canManageTabRoom(Auth::user(), $version), 403);

        $this->version = $version;

        if ($this->selectedCandidateId !== null) {
            $this->loadCandidate($this->selectedCandidateId, $adjudication);
        }
    }

    public function search(AdjudicationService $adjudication): void
    {
        $result = $adjudication->findCandidate(
            $this->version,
            $this->candidateIdSearch !== '' ? $this->candidateIdSearch : null,
            $this->lastNameSearch !== '' ? $this->lastNameSearch : null,
        );

        if ($result['candidate'] !== null) {
            $this->loadCandidate($result['candidate']->id, $adjudication);

            return;
        }

        if ($result['matches']->isNotEmpty()) {
            $this->selectedCandidateId = null;
            $this->matches = $result['matches']->map(fn (Candidate $candidate): array => [
                'id' => $candidate->id,
                'name' => $candidate->student->user->name,
                'voicePart' => $candidate->voicePart->name,
            ])->values()->all();

            return;
        }

        $this->selectedCandidateId = null;
        $this->matches = [];

        Flux::toast(text: 'No candidate in this version matched that search.', variant: 'danger');
    }

    public function selectCandidate(int $candidateId, AdjudicationService $adjudication): void
    {
        $this->loadCandidate($candidateId, $adjudication);
    }

    public function selectRoom(int $roomId, AdjudicationService $adjudication): void
    {
        $this->selectedRoomId = $roomId;

        $room = VersionRoom::findOrFail($roomId);
        $defaultJudge = $adjudication->roomJudgesOrdered($room)->first();
        $this->selectedJudgeId = $defaultJudge?->id;

        $this->loadScores($adjudication);
    }

    public function selectJudge(int $judgeId, AdjudicationService $adjudication): void
    {
        $this->selectedJudgeId = $judgeId;
        $this->loadScores($adjudication);
    }

    public function changeVoicePart(AdjudicationService $adjudication): void
    {
        abort_if($this->version->isClosed(), 422);

        $candidate = Candidate::where('version_id', $this->version->id)->findOrFail($this->selectedCandidateId);

        $validated = $this->validate([
            'newVoicePartId' => ['required', 'integer', Rule::in($this->version->availableVoiceParts()->pluck('id')->all())],
            'recording_decision' => $candidate->hasRecordings()
                ? ['required', Rule::in(VoicePartRecordingDecision::answers())]
                : ['nullable'],
        ], [
            'newVoicePartId.required' => 'Please select a voice part.',
            'recording_decision.required' => 'Please say whether the recordings match the new voice part.',
        ]);

        $newVoicePart = VoicePart::findOrFail($validated['newVoicePartId']);

        if ($newVoicePart->id === $candidate->voice_part_id) {
            $this->addError('newVoicePartId', "The candidate is already {$newVoicePart->name}.");

            return;
        }

        $decision = VoicePartRecordingDecision::tryFrom($this->recording_decision) ?? VoicePartRecordingDecision::Match;
        $adjudication->changeVoicePart($candidate, $newVoicePart, $decision);

        $this->newVoicePartId = '';
        $this->recording_decision = '';
        // Flux's <flux:modal> Alpine component listens for a document-level
        // "modal-close" event carrying {name}, not "close-modal" — see
        // handleClose() in vendor/livewire/flux-pro/dist/flux.js.
        $this->dispatch('modal-close', name: 'change-voice-part');
        Flux::toast(text: "Voice part changed to {$newVoicePart->name}. All previously entered scores were removed.".($decision === VoicePartRecordingDecision::Remove ? ' The recordings were removed too.' : ''), variant: 'warning');

        $this->loadCandidate($candidate->id, $adjudication);
    }

    public function save(AdjudicationService $adjudication): void
    {
        abort_if($this->version->isClosed(), 422);

        $candidate = Candidate::findOrFail($this->selectedCandidateId);
        $judge = RoomJudge::findOrFail($this->selectedJudgeId);

        $factors = $adjudication->roomRubric($judge->room)
            ->flatMap(fn (ScoreCategory $category) => $category->scoreFactors);

        $rules = [];
        foreach ($factors as $factor) {
            $rules["scores.{$factor->id}"] = ['required', Rule::in($adjudication->optionsForFactor($factor))];
        }

        $this->validate($rules, [
            'scores.*.required' => 'Select a score for every factor before saving.',
            'scores.*.in' => 'Select a valid score for every factor before saving.',
        ]);

        $adjudication->saveScores($judge, $candidate, $this->version, $this->scores, overriddenByUserId: Auth::id());

        Flux::toast(text: "Scores saved for candidate #{$candidate->id}.", variant: 'success');
    }

    public function render(AdjudicationService $adjudication): View
    {
        $candidate = $this->selectedCandidateId !== null
            ? Candidate::with(['student.user', 'voicePart', 'school', 'teacher.user'])->find($this->selectedCandidateId)
            : null;

        $rooms = $candidate !== null ? $adjudication->roomsForCandidate($candidate) : collect();
        $selectedRoom = $this->selectedRoomId !== null ? $rooms->firstWhere('id', $this->selectedRoomId) : null;
        $selectedJudge = $this->selectedJudgeId !== null ? RoomJudge::find($this->selectedJudgeId) : null;

        $rubric = $selectedRoom !== null ? $adjudication->roomRubric($selectedRoom) : collect();
        $factorOptions = $rubric
            ->flatMap(fn (ScoreCategory $category) => $category->scoreFactors)
            ->mapWithKeys(fn ($factor) => [$factor->id => $adjudication->optionsForFactor($factor)]);

        $judgeSummary = ($selectedRoom !== null && $candidate !== null)
            ? $adjudication->judgeScoreSummaryForCandidate($selectedRoom, $candidate)
            : collect();

        $roomScores = ($selectedRoom !== null && $candidate !== null)
            ? $adjudication->scoresForCandidate($selectedRoom, $candidate)
            : collect();

        // Same recordings a judge in the selected Room hears (Adjudicate.php),
        // but play-only: no trackRecording/recordDuration, so a manager
        // listening never shows up in the judge playback analytics.
        $showRecordings = $this->version->getRawOriginal('audition_type') === AuditionType::Remote->value;

        $recordings = ($showRecordings && $selectedRoom !== null && $candidate !== null)
            ? $adjudication->recordingsForCandidate($selectedRoom, $candidate)
            : collect();

        // Live, not cached — reflects whatever was just saved, since
        // candidateTolerances() is a fresh aggregate query every call (same
        // convention as Adjudicate.php's Room Scoring table).
        $inTolerance = ($selectedRoom !== null && $candidate !== null)
            ? $adjudication->candidateTolerances($selectedRoom, collect([$candidate])->pluck('id'))[$candidate->id] ?? true
            : true;

        return view('livewire.events.tab-room.add-edit-scores', [
            'candidate' => $candidate,
            'rooms' => $rooms,
            'selectedRoom' => $selectedRoom,
            'selectedJudge' => $selectedJudge,
            'roomJudgesOrdered' => $selectedRoom !== null ? $adjudication->roomJudgesOrdered($selectedRoom) : collect(),
            'rubric' => $rubric,
            'factorOptions' => $factorOptions,
            'judgeSummary' => $judgeSummary,
            'roomScores' => $roomScores,
            'showRecordings' => $showRecordings,
            'recordings' => $recordings,
            'inTolerance' => $inTolerance,
            'availableVoiceParts' => $this->version->availableVoiceParts(),
            'candidateHasRecordings' => $candidate?->hasRecordings() ?? false,
            'versionIsClosed' => $this->version->isClosed(),
            // getRawOriginal(), not the enum cast — Larastan can't infer it
            // through the method-based casts() (see PHPStan-quirks memory).
            'candidateStatus' => $candidate !== null ? CandidateStatus::from((string) $candidate->getRawOriginal('status')) : null,
        ]);
    }

    private function loadCandidate(int $candidateId, AdjudicationService $adjudication): void
    {
        $candidate = Candidate::with(['student.user', 'voicePart', 'school', 'teacher.user'])->find($candidateId);

        if ($candidate === null) {
            $this->selectedCandidateId = null;
            $this->matches = [];

            return;
        }

        $this->selectedCandidateId = $candidateId;
        $this->recording_decision = '';
        $this->matches = [];
        $this->resetValidation();

        $rooms = $adjudication->roomsForCandidate($candidate);
        $firstRoom = $rooms->first();

        if ($firstRoom !== null) {
            $this->selectRoom($firstRoom->id, $adjudication);
        } else {
            $this->selectedRoomId = null;
            $this->selectedJudgeId = null;
            $this->scores = [];
        }
    }

    private function loadScores(AdjudicationService $adjudication): void
    {
        if ($this->selectedCandidateId === null || $this->selectedJudgeId === null) {
            $this->scores = [];

            return;
        }

        $candidate = Candidate::findOrFail($this->selectedCandidateId);
        $judge = RoomJudge::findOrFail($this->selectedJudgeId);

        $this->resetValidation();
        $this->scores = $adjudication->existingScoresFor($judge, $candidate);
    }
}
