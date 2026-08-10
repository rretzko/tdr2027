<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom;

use App\Models\Candidate;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Services\AdjudicationService;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tab Room Manager's Adjudication Tracking sub-module (Tab Room
 * Module.docx): overall progress bar, per-Room overview, and per-Candidate
 * badges with a judge-by-judge score breakdown on hover. Read-only — every
 * number here comes straight from AdjudicationService, the same source the
 * live Adjudicate page uses.
 */
#[Layout('components.layouts.app')]
class AdjudicationTracking extends Component
{
    public Version $version;

    #[Url]
    public ?int $selectedRoomId = null;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageTabRoom(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function selectRoom(int $roomId): void
    {
        $this->selectedRoomId = $roomId;
    }

    public function render(AdjudicationService $adjudication): View
    {
        $rooms = VersionRoom::where('version_id', $this->version->id)->orderBy('order_by')->get();

        $selectedRoom = $this->selectedRoomId !== null
            ? $rooms->firstWhere('id', $this->selectedRoomId)
            : $rooms->first();

        $versionProgress = $adjudication->versionProgress($this->version);

        $overallCounts = ['completed' => 0, 'partial' => 0, 'none' => 0, 'error' => 0];
        foreach ($versionProgress as $roomProgress) {
            foreach (['completed', 'partial', 'none', 'error'] as $bucket) {
                $overallCounts[$bucket] += $roomProgress[$bucket];
            }
        }
        $overallTotal = max(array_sum($overallCounts), 1);
        $overallPercentages = array_map(fn (int $count): float => round($count / $overallTotal * 100, 1), $overallCounts);

        // Red/Amber/Green summary per Room, for the Rooms selector — red if
        // any candidate is in the 'error' bucket or none have started yet,
        // green once every candidate is 'completed', amber otherwise
        // (in progress). Reuses versionProgress()'s counts rather than a
        // second query per room.
        $roomRag = $versionProgress->mapWithKeys(function (array $progress): array {
            $total = $progress['completed'] + $progress['partial'] + $progress['none'] + $progress['error'];

            $rag = match (true) {
                $progress['error'] > 0 => 'red',
                $total === 0 || ($progress['completed'] === 0 && $progress['partial'] === 0) => 'red',
                $progress['completed'] === $total => 'green',
                default => 'amber',
            };

            return [$progress['room']->id => $rag];
        });

        $roomOutOfTolerance = $rooms->mapWithKeys(
            fn (VersionRoom $room): array => [$room->id => $adjudication->roomHasOutOfToleranceCandidate($room)],
        );

        $candidates = collect();
        $statuses = [];
        $tolerances = [];
        $breakdown = [];
        $roomOverviewCounts = ['completed' => 0, 'partial' => 0, 'none' => 0, 'error' => 0];
        $roomJudgesOrdered = collect();
        $judgeProgress = collect();

        if ($selectedRoom !== null) {
            $candidates = $adjudication->candidatesForRoom($selectedRoom);
            $candidateIds = $candidates->pluck('id');

            // candidatesForRoom() returns a plain Support Collection (it
            // ends in ->sortBy()->values()), so the tooltip's extra
            // relations are loaded via a second keyed query rather than
            // Collection::load() — order from candidatesForRoom() (by voice
            // part, then candidate id) is preserved.
            $detailsById = Candidate::whereIn('id', $candidateIds)->with(['student.user', 'teacher.user', 'school'])->get()->keyBy('id');
            $candidates = $candidates->map(fn (Candidate $candidate): Candidate => $detailsById->get($candidate->id) ?? $candidate);
            $factorCount = $adjudication->factorCount($selectedRoom);

            $statuses = $adjudication->candidateStatuses($selectedRoom, $candidateIds);
            $tolerances = $adjudication->candidateTolerances($selectedRoom, $candidateIds);
            $breakdown = $adjudication->candidateJudgeBreakdown($selectedRoom, $candidateIds);
            $roomJudgesOrdered = $adjudication->roomJudgesOrdered($selectedRoom);

            $judgeProgress = $roomJudgesOrdered->map(function ($judge) use ($adjudication, $candidateIds, $factorCount): array {
                $completion = $adjudication->judgeCompletionFor($judge, $candidateIds, $factorCount);
                $scoredCount = count(array_filter($completion));

                return [
                    'judge' => $judge,
                    'scoredCount' => $scoredCount,
                    'percent' => $candidateIds->count() > 0 ? round($scoredCount / $candidateIds->count() * 100, 1) : 0.0,
                ];
            });

            foreach ($statuses as $status) {
                $roomOverviewCounts[$status]++;
            }
        }

        return view('livewire.events.tab-room.adjudication-tracking', [
            'rooms' => $rooms,
            'selectedRoom' => $selectedRoom,
            'versionProgress' => $versionProgress,
            'roomRag' => $roomRag,
            'roomOutOfTolerance' => $roomOutOfTolerance,
            'overallCounts' => $overallCounts,
            'overallPercentages' => $overallPercentages,
            'roomOverviewCounts' => $roomOverviewCounts,
            'roomJudgesOrdered' => $roomJudgesOrdered,
            'judgeProgress' => $judgeProgress,
            'candidates' => $candidates,
            'statuses' => $statuses,
            'tolerances' => $tolerances,
            'breakdown' => $breakdown,
        ]);
    }
}
