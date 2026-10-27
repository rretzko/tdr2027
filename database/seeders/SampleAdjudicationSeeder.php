<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CandidateStatus;
use App\Enums\CutoffStrategy;
use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Enums\ScoreOrder;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RoomJudge;
use App\Models\ScoreCategory;
use App\Models\ScoreFactor;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionRoom;
use App\Models\VoicePart;
use App\Services\AdjudicationService;
use App\Services\EnsembleCutoffService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Adds the judge/rubric/room adjudication pipeline (score_categories,
 * score_factors, version_rooms, room_score_categories, room_voice_parts,
 * room_judges, scores) and the Ensemble Cut-offs configuration
 * (ensemble_voice_parts, version_ensemble_order, versions.cutoff_strategy)
 * to the synthetic "Sample Honor Choir Association"
 * dataset built by SampleHonorChoirAssociationSeeder, which calls this
 * seeder as its last step. Can also run standalone on top of an
 * already-seeded environment:
 *
 *   php artisan db:seed --class=SampleAdjudicationSeeder
 *
 * Idempotent: re-running wipes every room, rubric, judge and score on the
 * sample Event first, including any configured by hand through the admin
 * screens, so don't run it after hand-building rooms you want to keep.
 *
 * Everything goes through the same paths the admin screens and judges use
 * (pivot sync, RoomJudge rows, AdjudicationService::saveScores()), so
 * scores carry the same denormalized order_by columns as real ones.
 *
 * What gets seeded:
 *  - Ensemble voice parts: Treble Honor Choir takes the treble parts,
 *    Bass-Baritone Honor Choir the rest, Mixed Honor Choir every part.
 *    Each sample Version orders them Treble, Bass-Baritone, Mixed and uses
 *    the "Multiple ensembles, alternating cutoff" strategy (one click per
 *    voice part on the Ensemble Cut-offs page).
 *  - One event-level rubric (Scales + Solo, three factors each, 1-9).
 *  - Per Version, a "Treble Voices" and a "Tenor/Bass Voices" room, each
 *    with three synthetic judges (demo.judgeN@sample-honorchoir.example,
 *    password "password").
 *  - Closed cycle: full scores for every Accepted/NotAccepted candidate
 *    (Accepted scored clearly better), none for NoShow, then a real
 *    EnsembleCutoffService::applyCutoff() per voice part at the line
 *    between them. That keeps every Accepted/NotAccepted status, but
 *    reassigns accepted_ensemble_id by the strategy (the base seeder picks
 *    ensembles at random) and rewrites each AuditionResult to the real
 *    seeded total.
 *  - Active cycle: Registered candidates spread across fully scored,
 *    partially scored (head judge only), one out-of-tolerance, and
 *    unscored, so Adjudication Tracking and Add/Edit Scores have every
 *    state to show.
 */
class SampleAdjudicationSeeder extends Seeder
{
    private const ORG_NAME = 'Sample County Honor Choir Association';

    private const CLOSED_VERSION_NAME = 'Fall 2025 Sample Cycle';

    private const ACTIVE_VERSION_NAME = 'Fall 2026 Sample Cycle';

    public const JUDGE_EMAIL_PATTERN = 'demo.judge%@sample-honorchoir.example';

    private const EVENT_MANAGER_EMAIL = 'demo.eventmanager@sample-honorchoir.example';

    private const TREBLE_ENSEMBLE = 'Treble Honor Choir';

    private const LOWER_ENSEMBLE = 'Bass-Baritone Honor Choir';

    private const MIXED_ENSEMBLE = 'Mixed Honor Choir';

    private const DEMO_PASSWORD = 'password';

    /** Judge-total spread allowed per candidate in each room. */
    private const ROOM_TOLERANCE = 8;

    /** @var list<array{first: string, last: string}> */
    private const JUDGE_NAMES = [
        ['first' => 'Marian', 'last' => 'Okafor'],
        ['first' => 'Theo', 'last' => 'Lindqvist'],
        ['first' => 'Rosa', 'last' => 'Delgado'],
        ['first' => 'Gareth', 'last' => 'Pryce'],
        ['first' => 'Helen', 'last' => 'Achterberg'],
        ['first' => 'Samuel', 'last' => 'Nakamura'],
    ];

    /** @var list<JudgeType> */
    private const JUDGE_TYPES = [JudgeType::HeadJudge, JudgeType::Judge2, JudgeType::Judge3];

    public function run(AdjudicationService $adjudication, EnsembleCutoffService $cutoffs): void
    {
        $organization = Organization::where('name', self::ORG_NAME)->first();
        $event = $organization !== null ? Event::where('organization_id', $organization->id)->first() : null;
        $closedVersion = $event?->versions()->where('name', self::CLOSED_VERSION_NAME)->first();
        $activeVersion = $event?->versions()->where('name', self::ACTIVE_VERSION_NAME)->first();

        if ($event === null || $closedVersion === null || $activeVersion === null) {
            $this->command->warn('SampleAdjudicationSeeder skipped: run SampleHonorChoirAssociationSeeder first.');

            return;
        }

        // CandidateObserver writes candidate_status_history.user_id (NOT
        // NULL) from Auth::id() whenever applyCutoff() changes a status —
        // same reason SampleHonorChoirAssociationSeeder logs in as the demo
        // Event Manager.
        Auth::login(User::where('email', self::EVENT_MANAGER_EMAIL)->firstOrFail());

        DB::transaction(function () use ($event, $closedVersion, $activeVersion, $adjudication, $cutoffs): void {
            $this->wipeExisting($event, collect([$closedVersion->id, $activeVersion->id]));

            [$trebleParts, $lowerParts] = $this->partitionVoiceParts();

            $this->configureEnsembles($event, [$closedVersion, $activeVersion], $trebleParts, $lowerParts);

            $categories = $this->buildRubric($event);

            foreach ([$closedVersion, $activeVersion] as $version) {
                $this->buildRooms($version, $categories, $trebleParts, $lowerParts);
            }

            $this->scoreClosedCycle($closedVersion, $adjudication);
            $this->applyClosedCycleCutoffs($closedVersion->fresh(), $cutoffs);
            $this->scoreActiveCycle($activeVersion, $adjudication);
        });

        Auth::logout();

        $this->command->info('Sample adjudication data seeded (ensemble cut-off setup, rubric, rooms, judges, scores).');
    }

    /**
     * Judge users are removed separately from rooms/scores because
     * SampleHonorChoirAssociationSeeder force-deletes the sample Versions,
     * which cascades RoomJudge rows but leaves the users behind.
     */
    public static function deleteJudgeUsers(): void
    {
        $judgeUserIds = User::where('email', 'like', self::JUDGE_EMAIL_PATTERN)->pluck('id');

        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $judgeUserIds)->delete();
        User::whereIn('id', $judgeUserIds)->delete();
    }

    /**
     * @param  Collection<int, int>  $versionIds
     */
    private function wipeExisting(Event $event, Collection $versionIds): void
    {
        // Scores, room_judges and both room pivots cascade from these.
        VersionRoom::whereIn('version_id', $versionIds)->delete();
        // Score factors cascade from their category.
        ScoreCategory::where('event_id', $event->id)->delete();

        VersionEnsembleOrder::whereIn('version_id', $versionIds)->delete();
        DB::table('ensemble_voice_parts')
            ->whereIn('ensemble_id', Ensemble::where('event_id', $event->id)->pluck('id'))
            ->delete();

        self::deleteJudgeUsers();
    }

    /**
     * Every voice part, split into treble and tenor/bass. Covers all of
     * them, not just the ones current candidates use, so a candidate
     * registered live during a demo still has an ensemble and a room.
     *
     * @return array{0: Collection<int, VoicePart>, 1: Collection<int, VoicePart>}
     */
    private function partitionVoiceParts(): array
    {
        [$trebleParts, $lowerParts] = VoicePart::query()->get()
            ->partition(fn (VoicePart $voicePart): bool => preg_match('/descant|soprano|alto|treble/i', $voicePart->name) === 1);

        return [$trebleParts->values(), $lowerParts->values()];
    }

    /**
     * @param  list<Version>  $versions
     * @param  Collection<int, VoicePart>  $trebleParts
     * @param  Collection<int, VoicePart>  $lowerParts
     */
    private function configureEnsembles(Event $event, array $versions, Collection $trebleParts, Collection $lowerParts): void
    {
        $ensembles = Ensemble::where('event_id', $event->id)->get()->keyBy('name');

        $voicePartsByEnsemble = [
            self::TREBLE_ENSEMBLE => $trebleParts,
            self::LOWER_ENSEMBLE => $lowerParts,
            self::MIXED_ENSEMBLE => $trebleParts->merge($lowerParts),
        ];

        foreach ($voicePartsByEnsemble as $name => $voiceParts) {
            $ensembles[$name]->voiceParts()->sync($voiceParts->pluck('id')->all());
        }

        // Alternating rotates each voice part's top score groups between
        // its eligible ensembles in this order, so the voice-specific choir
        // gets the first pick ahead of the Mixed choir.
        foreach ($versions as $version) {
            foreach ([self::TREBLE_ENSEMBLE, self::LOWER_ENSEMBLE, self::MIXED_ENSEMBLE] as $order => $name) {
                VersionEnsembleOrder::create([
                    'version_id' => $version->id,
                    'ensemble_id' => $ensembles[$name]->id,
                    'order_by' => $order + 1,
                ]);
            }

            $version->update(['cutoff_strategy' => CutoffStrategy::AlternatingEnsembleAssignment]);
        }
    }

    /**
     * @return Collection<int, ScoreCategory>
     */
    private function buildRubric(Event $event): Collection
    {
        $definitions = [
            'Scales' => [['Ascending', 'ASC'], ['Descending', 'DSC'], ['Chromatic', 'CHR']],
            'Solo' => [['Tone', 'TN'], ['Intonation', 'INT'], ['Musicality', 'MUS']],
        ];

        $categories = collect();
        $categoryOrder = 1;

        foreach ($definitions as $description => $factors) {
            $category = ScoreCategory::create([
                'event_id' => $event->id,
                'version_id' => null,
                'description' => $description,
                'order_by' => $categoryOrder++,
            ]);

            foreach ($factors as $factorOrder => [$factorDescription, $abbreviation]) {
                ScoreFactor::create([
                    'event_id' => $event->id,
                    'version_id' => null,
                    'score_category_id' => $category->id,
                    'description' => $factorDescription,
                    'abbreviation' => $abbreviation,
                    'best' => 1,
                    'worst' => 9,
                    'interval_by' => 1,
                    'multiplier' => 1,
                    'tolerance' => null,
                    'order_by' => $factorOrder + 1,
                ]);
            }

            $categories->push($category);
        }

        return $categories;
    }

    /**
     * @param  Collection<int, ScoreCategory>  $categories
     * @param  Collection<int, VoicePart>  $trebleParts
     * @param  Collection<int, VoicePart>  $lowerParts
     */
    private function buildRooms(Version $version, Collection $categories, Collection $trebleParts, Collection $lowerParts): void
    {
        $rooms = [
            ['name' => 'Treble Voices', 'voiceParts' => $trebleParts],
            ['name' => 'Tenor/Bass Voices', 'voiceParts' => $lowerParts],
        ];

        foreach ($rooms as $order => $definition) {
            if ($definition['voiceParts']->isEmpty()) {
                continue;
            }

            $room = VersionRoom::create([
                'version_id' => $version->id,
                'name' => $definition['name'],
                'tolerance' => self::ROOM_TOLERANCE,
                'order_by' => $order + 1,
            ]);

            $room->scoreCategories()->sync($categories->pluck('id')->all());
            $room->voiceParts()->sync($definition['voiceParts']->pluck('id')->all());

            // The same six judges serve both cycles, the way an association
            // reuses its adjudicator pool from year to year.
            foreach (self::JUDGE_TYPES as $index => $judgeType) {
                RoomJudge::create([
                    'version_id' => $version->id,
                    'room_id' => $room->id,
                    'user_id' => $this->judgeUser(($order * count(self::JUDGE_TYPES)) + $index)->id,
                    'judge_type' => $judgeType,
                    'status' => JudgeStatus::Assigned,
                ]);
            }
        }
    }

    private function judgeUser(int $index): User
    {
        $email = 'demo.judge'.($index + 1).'@sample-honorchoir.example';

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        return User::factory()->create([
            'first_name' => self::JUDGE_NAMES[$index]['first'],
            'last_name' => self::JUDGE_NAMES[$index]['last'],
            'email' => $email,
            'password' => Hash::make(self::DEMO_PASSWORD),
        ]);
    }

    private function scoreClosedCycle(Version $version, AdjudicationService $adjudication): void
    {
        $candidates = Candidate::where('version_id', $version->id)
            ->whereIn('status', [CandidateStatus::Accepted, CandidateStatus::NotAccepted])
            ->with('voicePart')
            ->get();

        foreach ($candidates as $candidate) {
            // Quality on a 1 (best) to 9 (worst) scale before score_order
            // is applied: Accepted clearly ahead of NotAccepted.
            $quality = $candidate->getRawOriginal('status') === CandidateStatus::Accepted->value
                ? fake()->numberBetween(2, 3)
                : fake()->numberBetween(6, 7);

            $this->scoreCandidate($candidate, $version, $adjudication, $quality, judgeLimit: null);
        }
    }

    /**
     * Applies each voice part's cutoff at the line between its seeded
     * Accepted and NotAccepted candidates, so every status is kept, but
     * ensembles and AuditionResults come from the real cut-off logic. A
     * voice part with no Accepted candidate gets a cutoff better than its
     * best total, which rejects everyone.
     */
    private function applyClosedCycleCutoffs(Version $version, EnsembleCutoffService $cutoffs): void
    {
        $ascending = $version->getRawOriginal('score_order') === ScoreOrder::Asc->value;

        foreach ($version->availableVoiceParts() as $voicePart) {
            $ranked = $cutoffs->rankedCandidates($version, $voicePart);

            if ($ranked->isEmpty()) {
                continue;
            }

            $acceptedTotals = $ranked
                ->filter(fn (array $row): bool => $row['candidate']->getRawOriginal('status') === CandidateStatus::Accepted->value)
                ->pluck('total');
            $allTotals = $ranked->pluck('total');

            $cutoff = match (true) {
                $acceptedTotals->isNotEmpty() => $ascending ? $acceptedTotals->max() : $acceptedTotals->min(),
                default => $ascending ? $allTotals->min() - 1 : $allTotals->max() + 1,
            };

            $cutoffs->applyCutoff($version, $voicePart, (int) $cutoff);
        }
    }

    /**
     * Registered candidates, in a stable order, cycle through four
     * adjudication states so every Tab Room screen has something to show.
     */
    private function scoreActiveCycle(Version $version, AdjudicationService $adjudication): void
    {
        $candidates = Candidate::where('version_id', $version->id)
            ->where('status', CandidateStatus::Registered)
            ->with('voicePart')
            ->orderBy('id')
            ->get();

        foreach ($candidates->values() as $index => $candidate) {
            match ($index % 4) {
                0 => $this->scoreCandidate($candidate, $version, $adjudication, fake()->numberBetween(2, 6), judgeLimit: null),
                1 => $this->scoreCandidate($candidate, $version, $adjudication, fake()->numberBetween(2, 6), judgeLimit: 1),
                2 => $this->scoreCandidate($candidate, $version, $adjudication, 3, judgeLimit: null, outlierJudge: true),
                default => null, // Not yet scored.
            };
        }
    }

    /**
     * @param  int  $quality  1 (best) to 9 (worst), before score_order is applied
     * @param  int|null  $judgeLimit  score only this many judges (in judge-type order), or all
     * @param  bool  $outlierJudge  have the last judge score far worse, breaking the room tolerance
     */
    private function scoreCandidate(Candidate $candidate, Version $version, AdjudicationService $adjudication, int $quality, ?int $judgeLimit, bool $outlierJudge = false): void
    {
        $ascending = $version->getRawOriginal('score_order') === ScoreOrder::Asc->value;

        foreach ($adjudication->roomsForCandidate($candidate) as $room) {
            $judges = $adjudication->roomJudgesOrdered($room);
            $judges = $judgeLimit !== null ? $judges->take($judgeLimit) : $judges;
            $factors = $adjudication->roomRubric($room)->flatMap(fn (ScoreCategory $category) => $category->scoreFactors);

            foreach ($judges->values() as $judgeIndex => $judge) {
                $isOutlier = $outlierJudge && $judgeIndex === $judges->count() - 1;

                $scores = $factors->mapWithKeys(function (ScoreFactor $factor) use ($quality, $isOutlier, $ascending): array {
                    // Small per-factor noise (0-1) keeps the judge-total
                    // spread within ROOM_TOLERANCE; the outlier's +4 per
                    // factor pushes it well past.
                    $value = min(9, max(1, $quality + fake()->numberBetween(0, 1) + ($isOutlier ? 4 : 0)));

                    return [$factor->id => $ascending ? $value : 10 - $value];
                })->all();

                $adjudication->saveScores($judge, $candidate, $version, $scores);
            }
        }
    }
}
