<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\AuditionType;
use App\Enums\VersionDateType;
use App\Models\Candidate;
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
    }

    public function save(AdjudicationService $adjudication): void
    {
        $candidate = $adjudication->candidatesForRoom($this->roomJudge->room)->firstWhere('id', $this->selectedCandidateId);

        abort_unless($candidate !== null, 404);

        $factors = $adjudication->roomRubric($this->roomJudge->room)
            ->flatMap(fn (ScoreCategory $category) => $category->scoreFactors);

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
            ? $adjudication->scoresForCandidate($this->version, $selectedCandidate)
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
        ]);
    }
}
