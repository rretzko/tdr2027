<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PageVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class FastPass
{
    public static function record(User $user, string $routeName, string $label): void
    {
        $visit = PageVisit::query()
            ->where('user_id', $user->id)
            ->where('route_name', $routeName)
            ->first();

        if ($visit !== null) {
            $visit->increment('visit_count');
            $visit->update(['label' => $label, 'last_visited_at' => now()]);

            return;
        }

        PageVisit::create([
            'user_id' => $user->id,
            'route_name' => $routeName,
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
            ->orderByDesc('visit_count')
            ->limit($limit)
            ->get();
    }
}
