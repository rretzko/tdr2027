<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PageVisit;
use App\Models\TrackablePage;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class FastPass
{
    private const CACHE_KEY = 'fast_pass.trackable_pages';

    /**
     * Returns the active trackable route map, cached for 5 minutes.
     *
     * @return array<string, string>
     */
    public static function activeRouteMap(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(5), function (): array {
            return TrackablePage::active()->pluck('label', 'route_name')->all();
        });
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function record(User $user, string $routeName, string $label, ?Version $version = null): void
    {
        $visit = PageVisit::query()
            ->where('user_id', $user->id)
            ->where('route_name', $routeName)
            ->where('version_id', $version?->id)
            ->first();

        if ($visit !== null) {
            $visit->increment('visit_count');
            $visit->update(['label' => $label, 'last_visited_at' => now()]);

            return;
        }

        PageVisit::create([
            'user_id' => $user->id,
            'route_name' => $routeName,
            'version_id' => $version?->id,
            'label' => $label,
            'visit_count' => 1,
            'last_visited_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, PageVisit>
     */
    public static function recentFor(User $user, int $limit = 5): Collection
    {
        return $user->pageVisits()
            ->with('version')
            ->orderByDesc('last_visited_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, PageVisit>
     */
    public static function topFor(User $user, int $limit = 5): Collection
    {
        return $user->pageVisits()
            ->with('version')
            ->orderByDesc('visit_count')
            ->limit($limit)
            ->get();
    }
}
