<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Models\Candidate;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionInvitation;
use App\Services\VersionInvitationEligibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    public function render(VersionInvitationEligibilityService $eligibility): View
    {
        return view('livewire.registrations.index', [
            'sections' => $this->buildSections($eligibility),
        ]);
    }

    /**
     * Returns three groups, each with its own qualifying rule — a Version
     * that satisfies none of them is left out entirely rather than shown
     * with a dead-end link:
     * - "open": the teacher window is currently open AND the teacher has an
     *   existing `version_invitations` row (any status) — a Version they can
     *   actually act on right now. Not filtered by current computed
     *   eligibility: an invited teacher whose eligibility has since lapsed
     *   (e.g. a `version_counties` reconfiguration) still belongs here,
     *   since the invitation itself is the standing that matters, not the
     *   pool computation.
     * - "eligible": the window is open, the teacher has NO invitation row,
     *   but is eligible per §5.4's `isEligible()` — the self-service
     *   discovery surface for §5.8's Request Invitation flow, kept visually
     *   separate from "open" so it never reads as something the teacher can
     *   act on immediately.
     * - "active": the window is not open AND the teacher has at least one
     *   candidate still in a registration state there (§8.3) — a closed
     *   Version with no candidates is dropped, and eligibility is not
     *   considered once the window has closed.
     *
     * All three groups are further constrained to Version::status === 'active'
     * — a Version whose lifecycle status has moved to closed belongs on the
     * Results page (ResultsIndex) instead, regardless of whether a stale
     * `version_dates` row still makes it look date-wise "open".
     *
     * @return array{open: Collection<int, array{version: Version, candidateCount: int, nextDate: VersionDate|null}>, eligible: Collection<int, array{version: Version, candidateCount: int, nextDate: VersionDate|null}>, active: Collection<int, array{version: Version, candidateCount: int, nextDate: VersionDate|null}>}
     */
    private function buildSections(VersionInvitationEligibilityService $eligibility): array
    {
        $teacher = $this->teacher();

        $openVersionIds = $eligibility->openForTeacherVersionIds();

        $candidateVersionIds = Candidate::where('teacher_id', $teacher->id)
            ->whereIn('status', ['eligible', 'pending', 'registered'])
            ->pluck('version_id')
            ->unique();

        $allVersionIds = $openVersionIds->merge($candidateVersionIds)->unique();

        if ($allVersionIds->isEmpty()) {
            return ['open' => collect(), 'eligible' => collect(), 'active' => collect()];
        }

        // Sorted here (descending senior_class_of, then ascending name) so
        // the three buckets built below via push() inherit the order for
        // free — avoids sorting the composite array-shape entries
        // afterward, which trips PHPStan's Collection-template-invariance
        // limitation when a closure re-types through push()/sort()/values().
        $versions = Version::with(['event', 'dates'])
            ->whereIn('id', $allVersionIds)
            ->where('status', 'active')
            ->get()
            ->sort(function (Version $a, Version $b): int {
                $seniorClassOf = $b->senior_class_of <=> $a->senior_class_of;

                return $seniorClassOf !== 0 ? $seniorClassOf : $a->name <=> $b->name;
            });

        $counts = Candidate::where('teacher_id', $teacher->id)
            ->whereIn('version_id', $allVersionIds)
            ->selectRaw('version_id, count(*) as total')
            ->groupBy('version_id')
            ->pluck('total', 'version_id');

        $invitedVersionIds = VersionInvitation::where('teacher_id', $teacher->id)
            ->whereIn('version_id', $allVersionIds)
            ->pluck('version_id');

        $now = Carbon::now();

        $open = collect();
        $eligibleToRequest = collect();
        $active = collect();

        foreach ($versions as $version) {
            $isOpenWindow = $openVersionIds->contains($version->id);
            $isInvited = $invitedVersionIds->contains($version->id);
            $hasCandidates = $candidateVersionIds->contains($version->id);

            $bucket = match (true) {
                $isOpenWindow && $isInvited => 'open',
                $isOpenWindow && $eligibility->isEligible($version, $teacher) => 'eligible',
                ! $isOpenWindow && $hasCandidates => 'active',
                default => null,
            };

            if ($bucket === null) {
                continue;
            }

            $nextDate = $version->dates
                ->filter(fn (VersionDate $d): bool => $d->getRawOriginal('start_at') !== null)
                ->sortBy(fn (VersionDate $d): string => (string) ($d->getRawOriginal('start_at') ?? ''))
                ->first(fn (VersionDate $d): bool => Carbon::parse((string) $d->getRawOriginal('start_at'))->gt($now));

            $entry = [
                'version' => $version,
                'candidateCount' => (int) ($counts[$version->id] ?? 0),
                'nextDate' => $nextDate,
            ];

            match ($bucket) {
                'open' => $open->push($entry),
                'eligible' => $eligibleToRequest->push($entry),
                'active' => $active->push($entry),
            };
        }

        return ['open' => $open, 'eligible' => $eligibleToRequest, 'active' => $active];
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
