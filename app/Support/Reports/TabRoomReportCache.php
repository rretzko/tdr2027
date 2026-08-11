<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Version;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Report-data caching for the Tab Room Reports sub-module
 * (TabRoomReportService) — these reports are static once Ensemble Cut-offs
 * are established, changing only when a Score is saved
 * (AdjudicationService::saveScores()) or a cutoff decision changes
 * (EnsembleCutoffService's applyCutoff()/applyEnsembleCutoff()/
 * finalizeVoicePart()), both of which call forget() on write.
 *
 * Invalidation is generation-based rather than key deletion — this app's
 * CACHE_STORE is 'database', which has no tag support, so there is no way
 * to bulk-delete "every report cache entry for this Version" by pattern.
 * forget() instead bumps a per-Version generation counter that's baked into
 * every report cache key; a bump makes every previously-cached key
 * unreachable without needing to know what those keys were. The now-orphaned
 * old entries aren't deleted, only left to expire via TTL_DAYS — acceptable
 * here since report cache entries are small and Tab Room activity is
 * bounded to the audition season, not an unbounded background accumulation.
 *
 * remember()'s callback must return a *plain* array/scalar tree — no
 * Eloquent models, no Collections, no other objects. config/cache.php sets
 * `serializable_classes => false`, so every store's unserialize() call
 * passes `['allowed_classes' => false]`: any object coming back out of the
 * cache — even a plain Illuminate\Support\Collection — is silently replaced
 * with `__PHP_Incomplete_Class`. This bit the Tab Room Reports in production
 * (2026-08-11) via TabRoomReportService, which caches plain arrays and
 * reconstitutes Collections/models after every read. See
 * TabRoomReportService::hydrateCandidateRows()'s docblock for the full story.
 */
final class TabRoomReportCache
{
    private const TTL_DAYS = 7;

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public static function remember(Version $version, string $key, Closure $callback): mixed
    {
        return Cache::remember(self::cacheKey($version, $key), now()->addDays(self::TTL_DAYS), $callback);
    }

    /**
     * Invalidates every report previously cached for this Version — call
     * after any write that could change a Tab Room report's output
     * (a Score save, or a cutoff accept/reject/no-show/incomplete
     * transition).
     */
    public static function forget(Version $version): void
    {
        $generationKey = self::generationKey($version);
        $current = Cache::get($generationKey, 0);

        // Not Cache::increment() — the database cache store's increment()
        // returns false (a no-op) rather than creating the key when it
        // doesn't already exist yet, which the very first invalidation for
        // a Version always hits.
        Cache::put($generationKey, $current + 1, now()->addDays(self::TTL_DAYS));
    }

    /**
     * The current generation number for $version — bumps every time
     * forget() runs. Exposed so a caller can tag a *separately* cached
     * artifact (e.g. a generated PDF stored on S3, see
     * GenerateCombinedScoresPdfJob) with "the report state this was built
     * from," and later compare against a fresh call here to decide whether
     * that artifact is still current, without duplicating the generation
     * key format outside this class.
     */
    public static function currentGeneration(Version $version): int
    {
        return (int) Cache::get(self::generationKey($version), 0);
    }

    private static function cacheKey(Version $version, string $key): string
    {
        $generation = self::currentGeneration($version);

        return "tab_room_reports:v{$generation}:{$version->id}:{$key}";
    }

    private static function generationKey(Version $version): string
    {
        return "tab_room_reports:gen:{$version->id}";
    }
}
