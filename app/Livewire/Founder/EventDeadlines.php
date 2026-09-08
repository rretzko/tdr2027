<?php

declare(strict_types=1);

namespace App\Livewire\Founder;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\VersionDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
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
            ->with(['versions' => function ($query): void {
                $query->whereIn('status', [EventStatus::Active, EventStatus::Sandbox])
                    ->with('dates')
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
        ]);
    }

    /**
     * Next upcoming deadline first (soonest ascending), then past deadlines
     * below it, most recent first — so "next" always sits at the top of the
     * stack, with history kept visible but demoted rather than hidden.
     *
     * @param  Collection<int, VersionDate>  $dates
     * @return Collection<int, VersionDate>
     */
    private function orderDates(Collection $dates): Collection
    {
        [$upcoming, $past] = $dates->partition(
            fn (VersionDate $date) => Carbon::parse($date->end_at ?? $date->start_at)->isFuture()
        );

        return $upcoming->sortBy('start_at')->values()
            ->merge($past->sortByDesc('start_at')->values());
    }
}
