<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\VersionDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class EventDeadlines extends Component
{
    public function render(): View
    {
        $events = Event::query()
            ->whereHas('versions', fn ($query) => $query->whereIn('status', [EventStatus::Active, EventStatus::Sandbox]))
            ->with(['ensembles', 'versions' => function ($query): void {
                $query->whereIn('status', [EventStatus::Active, EventStatus::Sandbox])
                    ->with(['dates', 'ensembleOrder'])
                    ->withCount(['candidates as registrant_count' => fn ($q) => $q->whereIn('status', CandidateStatus::registrantStates())])
                    ->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        $events->each(function (Event $event): void {
            $event->versions->each(function ($version): void {
                $version->setRelation('dates', $this->orderDates($version->dates));
            });
        });

        return view('livewire.founder.event-deadlines', [
            'events' => $events,
            'accepted' => $this->acceptedByVersion($events),
        ]);
    }

    /**
     * Accepted Candidates per Version, broken down by every Ensemble of the
     * Version's Event — all zeros until results are released and
     * EnsembleCutoffService moves Registered Candidates to Accepted. Ensembles
     * follow the Version's Tab Room order where set, otherwise by name.
     *
     * @param  Collection<int, Event>  $events
     * @return array<int, array{total: int, ensembles: list<array{name: string, count: int}>}>
     */
    private function acceptedByVersion(Collection $events): array
    {
        $versionIds = $events->flatMap(fn (Event $event) => $event->versions->pluck('id'))->all();

        $counts = DB::table('candidates')
            ->whereIn('version_id', $versionIds)
            ->where('status', CandidateStatus::Accepted->value)
            ->whereNotNull('accepted_ensemble_id')
            ->groupBy('version_id', 'accepted_ensemble_id')
            ->get(['version_id', 'accepted_ensemble_id', DB::raw('COUNT(*) as total')])
            ->mapWithKeys(fn (object $row): array => ["{$row->version_id}:{$row->accepted_ensemble_id}" => (int) $row->total]);

        $accepted = [];

        foreach ($events as $event) {
            foreach ($event->versions as $version) {
                $position = $version->ensembleOrder->pluck('order_by', 'ensemble_id');

                $ensembles = $event->ensembles
                    ->sortBy([
                        fn (Ensemble $a, Ensemble $b): int => ($position[$a->id] ?? PHP_INT_MAX) <=> ($position[$b->id] ?? PHP_INT_MAX),
                        fn (Ensemble $a, Ensemble $b): int => strcmp($a->name, $b->name),
                    ])
                    ->map(fn (Ensemble $ensemble): array => [
                        'name' => $ensemble->name,
                        'count' => $counts["{$version->id}:{$ensemble->id}"] ?? 0,
                    ])
                    ->values()
                    ->all();

                $accepted[$version->id] = [
                    'total' => array_sum(array_column($ensembles, 'count')),
                    'ensembles' => $ensembles,
                ];
            }
        }

        return $accepted;
    }

    /**
     * Upcoming dates first, ordered by their next boundary (soonest first),
     * then past deadlines below, most recent first — so "next" always sits at
     * the top of the stack, with history kept visible but demoted.
     *
     * The next boundary is start_at for a date that hasn't begun yet, and
     * end_at for a window that's already open — so a long-running open window
     * (e.g. a Rehearsal span entered months wide) ranks by when it closes, not
     * by a start that's already passed.
     *
     * @param  Collection<int, VersionDate>  $dates
     * @return Collection<int, VersionDate>
     */
    private function orderDates(Collection $dates): Collection
    {
        [$upcoming, $past] = $dates->partition(
            fn (VersionDate $date) => Carbon::parse($date->end_at ?? $date->start_at)->isFuture()
        );

        return $upcoming->sortBy(fn (VersionDate $date): int => $this->nextBoundary($date)->getTimestamp())->values()
            ->merge($past->sortByDesc('start_at')->values());
    }

    private function nextBoundary(VersionDate $date): Carbon
    {
        $start = Carbon::parse($date->start_at);

        return $start->isFuture() || $date->end_at === null
            ? $start
            : Carbon::parse($date->end_at);
    }
}
