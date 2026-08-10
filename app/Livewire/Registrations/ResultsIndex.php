<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Teacher;
use App\Models\Version;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Flat list of Versions this teacher had candidates in whose results have
 * been released (Tab Room Module.docx's Close Audition —
 * `results_released_at !== null`, not the Version's own `status`, since a
 * Version can be Closed via VersionEdit's general Status field for
 * unrelated reasons without releasing results — see TabRoom\CloseAudition)
 * — the counterpart to Registrations\Index (which is filtered to
 * status=active), linking each entry to the Results page.
 */
#[Layout('components.layouts.app')]
class ResultsIndex extends Component
{
    public function render(): View
    {
        return view('livewire.registrations.results-index', [
            'items' => $this->buildItems(),
        ]);
    }

    /**
     * @return Collection<int, array{version: Version, candidateCount: int}>
     */
    private function buildItems(): Collection
    {
        $teacher = $this->teacher();

        $versionIds = Candidate::where('teacher_id', $teacher->id)->pluck('version_id')->unique();

        if ($versionIds->isEmpty()) {
            return collect();
        }

        // Counts the four actual audition outcomes, matching what the
        // Results page itself lists — a Version where every candidate has
        // since moved on (withdrawn, etc.) still appears below, just with a
        // 0 badge.
        $counts = Candidate::where('teacher_id', $teacher->id)
            ->whereIn('version_id', $versionIds)
            ->whereIn('status', [
                CandidateStatus::Accepted,
                CandidateStatus::NotAccepted,
                CandidateStatus::NoShow,
                CandidateStatus::Incomplete,
            ])
            ->selectRaw('version_id, count(*) as total')
            ->groupBy('version_id')
            ->pluck('total', 'version_id');

        return Version::with('event')
            ->whereIn('id', $versionIds)
            ->whereNotNull('results_released_at')
            ->get()
            ->sort(function (Version $a, Version $b): int {
                $seniorClassOf = $b->senior_class_of <=> $a->senior_class_of;

                return $seniorClassOf !== 0 ? $seniorClassOf : $a->name <=> $b->name;
            })
            ->map(fn (Version $version): array => [
                'version' => $version,
                'candidateCount' => (int) ($counts[$version->id] ?? 0),
            ])
            ->values();
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
