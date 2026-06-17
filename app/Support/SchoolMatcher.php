<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\School;
use Illuminate\Support\Collection;

final class SchoolMatcher
{
    private const MIN_PERCENT = 70.0;

    /**
     * Score floor for a substring match. similar_text()'s percentage is penalized
     * heavily when one string is much shorter than the other, so a short partial
     * search like "Ridge" scores well under MIN_PERCENT against "Ridge High School"
     * even though it's an exact prefix — substring matches are given this floor instead.
     */
    private const SUBSTRING_MATCH_PERCENT = 90.0;

    private const MAX_SUGGESTIONS = 5;

    /**
     * Rank existing schools/studios by similarity to a typed name, so a teacher
     * is shown likely matches before being allowed to create a duplicate.
     *
     * Narrows candidates to the given state and (when it actually yields results)
     * exact zip code first, since zip is far more selective than state alone;
     * falls back to county/state-wide candidates when zip doesn't narrow it down.
     *
     * When no name has been typed yet but a zip code narrowed the candidates down,
     * lists those candidates directly — zip alone is selective enough that a teacher
     * shouldn't have to type a name first just to see what's already at that zip.
     *
     * @return Collection<int, array{school: School, percent: float}>
     */
    public static function suggestions(string $name, ?int $geostateId, ?string $zipCode, ?int $countyId): Collection
    {
        $name = trim($name);
        $zipCode = trim((string) $zipCode);

        if ($name === '' && $zipCode === '') {
            return collect();
        }

        $candidates = self::candidates($geostateId, $zipCode, $countyId);

        if ($name === '') {
            return $candidates
                ->take(self::MAX_SUGGESTIONS)
                ->map(fn (School $school) => ['school' => $school, 'percent' => 100.0])
                ->values();
        }

        $needle = mb_strtolower($name);

        return $candidates
            ->map(fn (School $school) => ['school' => $school, 'percent' => self::score($needle, mb_strtolower($school->name))])
            ->filter(fn (array $match) => $match['percent'] >= self::MIN_PERCENT)
            ->sortByDesc('percent')
            ->take(self::MAX_SUGGESTIONS)
            ->values();
    }

    private static function score(string $needle, string $haystack): float
    {
        similar_text($needle, $haystack, $percent);

        if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
            return max($percent, self::SUBSTRING_MATCH_PERCENT);
        }

        return $percent;
    }

    /**
     * @return Collection<int, School>
     */
    private static function candidates(?int $geostateId, ?string $zipCode, ?int $countyId): Collection
    {
        $zipCode = trim((string) $zipCode);

        if ($geostateId !== null && $zipCode !== '') {
            $byZip = School::query()
                ->where('geostate_id', $geostateId)
                ->where('zip_code', $zipCode)
                ->get();

            if ($byZip->isNotEmpty()) {
                return $byZip;
            }
        }

        $query = School::query();

        if ($geostateId !== null) {
            $query->where('geostate_id', $geostateId);
        }

        if ($countyId !== null) {
            $query->where('county_id', $countyId);
        }

        return $query->limit(500)->get();
    }
}
