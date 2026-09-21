<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\CandidateStatusHistory;
use App\Models\Ensemble;
use App\Models\Version;
use App\Models\VoicePart;
use Illuminate\Support\Collection;

/**
 * Enforces Version::audition_cap_per_school — the max number of active
 * candidates one school may have per "audition group" (see
 * auditionGroupVoicePartIds() below). Null means unrestricted, the same
 * "empty = unrestricted" convention as Version::counties()/EventGrade/
 * EnsembleGrade.
 */
final class AuditionCapService
{
    /**
     * The connected component of Ensembles/VoiceParts reachable from
     * $voicePart within the Version's parent Event — e.g. a Junior High
     * SATB/SSA split shares Soprano/Alto voice parts, so both ensembles'
     * voice parts fall into one group, while an Elementary ensemble with
     * its own exclusive voice parts (Treble I/II/III) forms a separate
     * group. This is what "per ensemble" means for the cap: a director
     * auditioning a Soprano doesn't yet know whether Ensemble Cut-offs will
     * land them in SATB or SSA, so both draw from the same 16-slot pool.
     *
     * Walks the Event's whole Ensemble/VoicePart graph directly (not
     * Version::ensembleOrder, which may not be populated yet at
     * registration time, well before Ensemble Cut-offs is ever opened).
     *
     * @return Collection<int, int> VoicePart IDs, including $voicePart itself
     */
    public function auditionGroupVoicePartIds(Version $version, VoicePart $voicePart): Collection
    {
        $ensembles = Ensemble::where('event_id', $version->event_id)
            ->with('voiceParts')
            ->get();

        $voicePartIds = collect([$voicePart->id]);
        $ensembleIds = collect();

        do {
            $before = $voicePartIds->count() + $ensembleIds->count();

            $newEnsembleIds = $ensembles
                ->filter(fn (Ensemble $ensemble): bool => $ensemble->voiceParts->pluck('id')->intersect($voicePartIds)->isNotEmpty())
                ->pluck('id');
            $ensembleIds = $ensembleIds->merge($newEnsembleIds)->unique();

            $newVoicePartIds = $ensembles
                ->whereIn('id', $ensembleIds)
                ->flatMap(fn (Ensemble $ensemble): Collection => $ensemble->voiceParts->pluck('id'));
            $voicePartIds = $voicePartIds->merge($newVoicePartIds)->unique();

            $after = $voicePartIds->count() + $ensembleIds->count();
        } while ($after > $before);

        return $voicePartIds->values();
    }

    /**
     * The Ensembles reachable in $voicePart's audition group, for banner
     * copy — e.g. "Junior High Choir (SATB) / Junior High Choir (SSA)".
     *
     * @return Collection<int, string>
     */
    public function auditionGroupEnsembleNames(Version $version, VoicePart $voicePart): Collection
    {
        $voicePartIds = $this->auditionGroupVoicePartIds($version, $voicePart);

        return Ensemble::where('event_id', $version->event_id)
            ->whereHas('voiceParts', fn ($query) => $query->whereIn('voice_parts.id', $voicePartIds))
            ->pluck('name');
    }

    /**
     * Whether $candidate is within their school's audition-group cap for
     * this Version — ordering-based (first-added-first-served among still-
     * active candidates), not a static count, so a later withdrawal frees
     * the slot for whoever is next in line rather than permanently
     * favoring whoever finished their checklist first.
     *
     * Ordered by each candidate's earliest candidate_status_history row
     * (written by CandidateObserver::created() the moment a Candidate is
     * enrolled), not Candidate.id/created_at — Candidate.id is a
     * version_id + random-4-digit-suffix value (CandidateObserver::
     * assignId()), not monotonically increasing, and bulk auto-enrollment
     * can create many candidates within the same created_at second.
     */
    public function isWithinCap(Candidate $candidate): bool
    {
        $cap = $candidate->version->audition_cap_per_school;

        if ($cap === null) {
            return true;
        }

        $voicePartIds = $this->auditionGroupVoicePartIds($candidate->version, $candidate->voicePart);

        $orderedIds = Candidate::where('version_id', $candidate->version_id)
            ->where('school_id', $candidate->school_id)
            ->whereIn('voice_part_id', $voicePartIds)
            ->whereIn('status', array_map(fn (CandidateStatus $s): string => $s->value, CandidateStatus::registrationStates()))
            ->orderBy(
                CandidateStatusHistory::select('id')
                    ->whereColumn('candidate_id', 'candidates.id')
                    ->orderBy('id')
                    ->limit(1),
            )
            ->pluck('id');

        return $orderedIds->take($cap)->contains($candidate->id);
    }
}
