<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ensemble;
use App\Models\EnsembleHistory;
use App\Models\Version;
use Illuminate\Support\Collection;

/**
 * Query/business logic for the Ensemble Cut-offs page's "History" panel
 * (Tab Room Module.docx). Prior seasons predate this system tracking
 * Candidate::accepted_ensemble_id — there's no live Version/Candidate data
 * to compute them from (confirmed empty audition_results and no accepted
 * Candidates on any prior season's Version for this Event) — so counts are
 * entered manually against the EnsembleHistory table, independent of any
 * Version row. recordCurrentSeason() snapshots the *current* season's
 * already-computed EnsembleCutoffService::acceptedCounts() into the same
 * table, so next year's "prior two seasons" needs no manual re-entry for
 * the season that just closed.
 */
final class EnsembleHistoryService
{
    /**
     * The two (by default) most recent seasons before this Version's own —
     * e.g. senior_class_of 2026 → [2025, 2024]. Independent of whether a
     * sibling Version actually exists for those years.
     *
     * @return list<int>
     */
    public function priorSeasonYears(Version $version, int $count = 2): array
    {
        $current = $version->senior_class_of;

        return range($current - 1, $current - $count);
    }

    /**
     * Every recorded count for the given Ensembles across the given season
     * years, as a nested lookup: ensemble_id => season_year => voice_part_id => count.
     *
     * @param  Collection<int, Ensemble>  $ensembles
     * @param  list<int>  $seasonYears
     * @return array<int, array<int, array<int, int>>>
     */
    public function historyGrid(Collection $ensembles, array $seasonYears): array
    {
        $rows = EnsembleHistory::whereIn('ensemble_id', $ensembles->pluck('id'))
            ->whereIn('season_year', $seasonYears)
            ->get();

        $grid = [];
        foreach ($rows as $row) {
            $grid[$row->ensemble_id][$row->season_year][$row->voice_part_id] = $row->accepted_count;
        }

        return $grid;
    }

    /**
     * Upserts one Ensemble+season's counts, one row per Voice Part. A null
     * or empty-string count deletes that row rather than storing a
     * meaningless zero for "never entered."
     *
     * @param  array<int, int|string|null>  $countsByVoicePartId
     */
    public function saveHistoryRow(Ensemble $ensemble, int $seasonYear, array $countsByVoicePartId): void
    {
        foreach ($countsByVoicePartId as $voicePartId => $count) {
            if ($count === null || $count === '') {
                EnsembleHistory::where('ensemble_id', $ensemble->id)
                    ->where('voice_part_id', $voicePartId)
                    ->where('season_year', $seasonYear)
                    ->delete();

                continue;
            }

            EnsembleHistory::updateOrCreate(
                ['ensemble_id' => $ensemble->id, 'voice_part_id' => $voicePartId, 'season_year' => $seasonYear],
                ['accepted_count' => (int) $count],
            );
        }
    }

    /**
     * Snapshots the current season's already-decided accepted counts
     * (EnsembleCutoffService::acceptedCounts()) into EnsembleHistory keyed
     * by this Version's own senior_class_of. Called from VersionEdit's
     * general Status field whenever it's saved as Closed (Tab Room
     * Module.docx's Close Audition button is itself just "close the
     * audition and version" — one action) — deliberately not a manual
     * button on the Ensemble Cut-offs page, so it can't be missed, and a
     * reopen-correct-reclose cycle re-snapshots the corrected totals
     * automatically rather than leaving the first pass stale.
     */
    public function recordCurrentSeason(Version $version, EnsembleCutoffService $cutoffs): void
    {
        foreach ($cutoffs->acceptedCounts($version) as $row) {
            EnsembleHistory::updateOrCreate(
                ['ensemble_id' => $row['ensemble']->id, 'voice_part_id' => $row['voice_part_id'], 'season_year' => $version->senior_class_of],
                ['accepted_count' => $row['count']],
            );
        }
    }
}
