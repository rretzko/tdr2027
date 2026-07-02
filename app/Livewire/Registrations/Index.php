<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Models\Candidate;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    public function render(): View
    {
        return view('livewire.registrations.index', [
            'sections' => $this->buildSections(),
        ]);
    }

    /**
     * Returns two groups: open windows, then versions with existing candidates.
     *
     * @return array{open: Collection<int, array{version: Version, candidateCount: int, nextDate: VersionDate|null}>, active: Collection<int, array{version: Version, candidateCount: int, nextDate: VersionDate|null}>}
     */
    private function buildSections(): array
    {
        $teacher = $this->teacher();

        $openVersionIds = VersionDate::where('date_type', 'teacher')
            ->where('start_at', '<=', now())
            ->where(function ($q): void {
                $q->whereNull('end_at')->orWhere('end_at', '>=', now());
            })
            ->pluck('version_id');

        $candidateVersionIds = Candidate::where('teacher_id', $teacher->id)
            ->whereIn('status', ['eligible', 'pending', 'registered'])
            ->pluck('version_id')
            ->unique();

        $allVersionIds = $openVersionIds->merge($candidateVersionIds)->unique();

        if ($allVersionIds->isEmpty()) {
            return ['open' => collect(), 'active' => collect()];
        }

        $versions = Version::with(['event', 'dates'])
            ->whereIn('id', $allVersionIds)
            ->get();

        $counts = Candidate::where('teacher_id', $teacher->id)
            ->whereIn('version_id', $allVersionIds)
            ->selectRaw('version_id, count(*) as total')
            ->groupBy('version_id')
            ->pluck('total', 'version_id');

        $now = Carbon::now();

        $open = collect();
        $active = collect();

        foreach ($versions as $version) {
            $nextDate = $version->dates
                ->filter(fn (VersionDate $d): bool => $d->getRawOriginal('start_at') !== null)
                ->sortBy(fn (VersionDate $d): string => (string) ($d->getRawOriginal('start_at') ?? ''))
                ->first(fn (VersionDate $d): bool => Carbon::parse((string) $d->getRawOriginal('start_at'))->gt($now));

            $entry = [
                'version' => $version,
                'candidateCount' => (int) ($counts[$version->id] ?? 0),
                'nextDate' => $nextDate,
            ];

            if ($openVersionIds->contains($version->id)) {
                $open->push($entry);
            } else {
                $active->push($entry);
            }
        }

        return ['open' => $open, 'active' => $active];
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
