<?php

declare(strict_types=1);

namespace App\Services\Readiness;

use App\Enums\ReadinessEditor;
use App\Enums\ReadinessPhase;
use App\Enums\ReadinessReviewState;
use App\Enums\ReadinessStatus;
use App\Enums\VersionDateType;
use App\Models\Event;
use App\Models\ScoreCategory;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionMailToAddress;
use App\Models\VersionReadinessReview;
use App\Services\VersionRoleAssignmentService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Evaluates a Version against ReadinessCatalog — the computed "punchlist"
 * that walks an Event Manager through every configuration decision.
 * See docs/plans/version-readiness.md.
 *
 * Status resolution per item:
 *  1. not applicable                    → NotApplicable
 *  2. data missing (NotStarted/InProgress) → that, regardless of any review —
 *     except an optional (non-blocking) item acknowledged while empty → Done
 *  3. flagged ReviewRequired (on clone) → NeedsReview, even if data is complete
 *  4. Acknowledged                      → Done
 *  5. otherwise                         → the item's own data-derived status
 */
class VersionReadiness
{
    public function __construct(private readonly VersionRoleAssignmentService $roles) {}

    /**
     * @return list<ReadinessItem>
     */
    public function items(): array
    {
        return ReadinessCatalog::items();
    }

    public function item(string $key): ?ReadinessItem
    {
        return collect($this->items())->first(fn (ReadinessItem $item): bool => $item->key === $key);
    }

    /**
     * @return Collection<string, ReadinessResult> keyed by item key, in catalog order
     */
    public function evaluate(Version $version): Collection
    {
        return $this->evaluateMany([$version])->get($version->id) ?? collect();
    }

    /**
     * evaluate() for several Versions at once, with a fixed number of
     * queries however many Versions there are (Events Show renders one
     * progress bar per Version). evaluate() delegates here, so there is a
     * single code path.
     *
     * @param  iterable<Version>  $versions
     * @return Collection<int, Collection<string, ReadinessResult>> keyed by version id
     */
    public function evaluateMany(iterable $versions): Collection
    {
        $items = $this->items();

        return $this->contexts(new EloquentCollection(collect($versions)->all()))
            ->map(fn (ReadinessContext $context): Collection => collect($items)
                ->mapWithKeys(fn (ReadinessItem $item): array => [$item->key => $this->resolve($item, $context)]));
    }

    /**
     * Incomplete blocking items at or before $upTo — what stands between
     * this Version and that lifecycle moment.
     *
     * @return Collection<string, ReadinessResult>
     */
    public function blockers(Version $version, ReadinessPhase $upTo): Collection
    {
        return $this->evaluate($version)->filter(
            fn (ReadinessResult $r): bool => $r->item->phase->position() <= $upTo->position() && $r->blocks(),
        );
    }

    /**
     * @param  Collection<string, ReadinessResult>  $results
     * @return array{done: int, total: int, percent: int, needsReview: int, phases: array<string, array{phase: ReadinessPhase, done: int, total: int, blocking: int}>}
     */
    public function summary(Collection $results): array
    {
        $applicable = $results->reject(fn (ReadinessResult $r): bool => $r->status === ReadinessStatus::NotApplicable);
        $done = $applicable->filter(fn (ReadinessResult $r): bool => $r->status === ReadinessStatus::Done)->count();
        $total = $applicable->count();

        $phases = [];
        foreach (ReadinessPhase::cases() as $phase) {
            $inPhase = $applicable->filter(fn (ReadinessResult $r): bool => $r->item->phase === $phase);
            $phases[$phase->value] = [
                'phase' => $phase,
                'done' => $inPhase->filter(fn (ReadinessResult $r): bool => $r->status === ReadinessStatus::Done)->count(),
                'total' => $inPhase->count(),
                'blocking' => $inPhase->filter(fn (ReadinessResult $r): bool => $r->blocks())->count(),
            ];
        }

        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total === 0 ? 100 : (int) floor($done / $total * 100),
            'needsReview' => $applicable->filter(fn (ReadinessResult $r): bool => $r->status === ReadinessStatus::NeedsReview)->count(),
            'phases' => $phases,
        ];
    }

    /**
     * Soft "due by" hint per phase (§9a Q6): the earliest Teacher-access
     * start for everything up to registration, Adjudication start for
     * auditions. Null when the anchoring date isn't set yet.
     *
     * @return array<string, ?CarbonInterface>
     */
    public function dueDates(Version $version): array
    {
        $version->loadMissing('dates');

        $start = function (VersionDateType $type) use ($version): ?CarbonInterface {
            $raw = $version->dates
                ->first(fn ($d): bool => $d->getRawOriginal('date_type') === $type->value)
                ?->getRawOriginal('start_at');

            return $raw === null ? null : Carbon::parse($raw);
        };

        $teacher = $start(VersionDateType::Teacher);

        return [
            ReadinessPhase::Structure->value => $teacher,
            ReadinessPhase::BeforeInvitations->value => $teacher,
            ReadinessPhase::BeforeRegistration->value => $teacher,
            ReadinessPhase::BeforeAuditions->value => $start(VersionDateType::Adjudication),
            ReadinessPhase::BeforeResults->value => null,
        ];
    }

    /**
     * Which kinds of readiness item $user may change on $version, keyed by
     * ReadinessEditor value — evaluated once per page, not per item.
     *
     * @return array<string, bool>
     */
    public function editorAccess(User $user, Version $version): array
    {
        return [
            ReadinessEditor::EventManager->value => $this->roles->canManageEvent($user, $version->event),
            ReadinessEditor::AuditionEnvironment->value => $this->roles->canManageAuditionEnvironment($user, $version),
            ReadinessEditor::RegistrationManager->value => $this->roles->canManageCoRegistrationManagers($user, $version),
        ];
    }

    public function acknowledge(Version $version, string $key, ?User $user): void
    {
        $this->writeReview($version, $key, ReadinessReviewState::Acknowledged, $user);
    }

    /**
     * Saving a VersionEdit tab counts as reviewing every item that tab
     * owns — the manager has just looked at (and submitted) those values.
     */
    public function markSectionReviewed(Version $version, string $section, ?User $user): void
    {
        foreach ($this->items() as $item) {
            if ($item->section === $section) {
                $this->acknowledge($version, $item->key, $user);
            }
        }
    }

    /**
     * Called once on a freshly cloned Version (§9a Q4): year-sensitive
     * values must be looked at again; every other confirmable value
     * carries last year's confirmation forward.
     */
    public function seedForClone(Version $version): void
    {
        foreach ($this->items() as $item) {
            if ($item->yearSensitive) {
                $this->writeReview($version, $item->key, ReadinessReviewState::ReviewRequired, null);
            } elseif ($item->acknowledgeable) {
                $this->writeReview($version, $item->key, ReadinessReviewState::Acknowledged, null);
            }
        }
    }

    private function writeReview(Version $version, string $key, ReadinessReviewState $state, ?User $user): void
    {
        VersionReadinessReview::updateOrCreate(
            ['version_id' => $version->id, 'item_key' => $key],
            ['state' => $state, 'user_id' => $user?->id, 'reviewed_at' => $state === ReadinessReviewState::Acknowledged ? now() : null],
        );
    }

    private function resolve(ReadinessItem $item, ReadinessContext $context): ReadinessResult
    {
        $reviewState = $context->reviews->get($item->key)?->getRawOriginal('state');
        $reviewRequired = $reviewState === ReadinessReviewState::ReviewRequired->value;
        $acknowledged = $reviewState === ReadinessReviewState::Acknowledged->value;

        if (! $item->isApplicable($context)) {
            $status = ReadinessStatus::NotApplicable;
        } else {
            $status = $item->baseStatus($context);

            // An optional item confirmed while empty means "we don't use this".
            if ($status === ReadinessStatus::NotStarted && ! $item->blocking && $acknowledged) {
                $status = ReadinessStatus::Done;
            } elseif (! $status->isIncomplete()) {
                $status = match (true) {
                    $reviewRequired => ReadinessStatus::NeedsReview,
                    $acknowledged => ReadinessStatus::Done,
                    default => $status,
                };
            }
        }

        return new ReadinessResult(
            item: $item,
            status: $status,
            detail: $status === ReadinessStatus::NotApplicable ? null : $item->detailFor($context),
            url: $item->urlFor($context->version),
            canAcknowledge: $status === ReadinessStatus::NeedsReview
                || ($item->acknowledgeable && $status === ReadinessStatus::NotStarted && ! $item->blocking),
        );
    }

    /**
     * @param  EloquentCollection<int, Version>  $versions
     * @return Collection<int, ReadinessContext> keyed by version id
     */
    private function contexts(EloquentCollection $versions): Collection
    {
        if ($versions->isEmpty()) {
            return collect();
        }

        $versions->loadMissing([
            'event.ensembles.grades',
            'event.ensembles.voiceParts',
            'dates', 'fees', 'membershipRequirement', 'counties', 'classOfs',
            'ensembleOrder', 'uploadFiles', 'pitchFiles', 'obligation', 'candidateApplication',
            'versionEpaymentConfig', 'rooms.roomJudges', 'readinessReviews',
        ]);

        $versionIds = $versions->modelKeys();
        $roles = $this->roles->assignmentsForVersions($versions);

        // user id => teacher id, for every role holder on any of these Versions.
        $teacherIdsByUser = Teacher::query()
            ->whereIn('user_id', $roles->flatten(2)->pluck('id')->unique())
            ->pluck('id', 'user_id');

        // Invitations, minus each Version's own role holders (the Event
        // Manager is auto-invited, which shouldn't count as "teachers invited").
        $invitationTotals = VersionInvitation::query()
            ->whereIn('version_id', $versionIds)
            ->selectRaw('version_id, count(*) as aggregate')
            ->groupBy('version_id')
            ->pluck('aggregate', 'version_id');
        $roleHolderInvitations = VersionInvitation::query()
            ->whereIn('version_id', $versionIds)
            ->whereIn('teacher_id', $teacherIdsByUser->values())
            ->get(['version_id', 'teacher_id']);

        $mailTo = VersionMailToAddress::query()
            ->whereIn('version_id', $versionIds)
            ->get(['version_id', 'user_id']);

        $rubrics = $this->rubrics($versions);

        $epaymentConfigs = $versions->pluck('event')->unique('id')
            ->mapWithKeys(fn (Event $event): array => [$event->id => $event->activeEpaymentConfig()]);

        return $versions->mapWithKeys(function (Version $version) use ($roles, $teacherIdsByUser, $invitationTotals, $roleHolderInvitations, $mailTo, $rubrics, $epaymentConfigs): array {
            $versionRoles = $roles->get($version->id, collect());
            $holderTeacherIds = $versionRoles->flatten()->pluck('id')
                ->map(fn (int $userId): ?int => $teacherIdsByUser->get($userId))
                ->filter()
                ->all();
            $registrationManagerIds = $versionRoles->get('Registration Manager', collect())->pluck('id')->all();

            $ownInvitations = $roleHolderInvitations
                ->filter(fn (VersionInvitation $i): bool => (int) $i->version_id === $version->id && in_array((int) $i->teacher_id, $holderTeacherIds, true))
                ->count();

            return [$version->id => new ReadinessContext(
                version: $version,
                ensembles: $version->event->ensembles,
                rubric: $rubrics->get($version->id, collect()),
                roles: $versionRoles,
                epaymentConfig: $epaymentConfigs->get($version->event_id),
                invitedTeacherCount: (int) $invitationTotals->get($version->id, 0) - $ownInvitations,
                registrationManagerHasMailTo: $mailTo->contains(
                    fn (VersionMailToAddress $m): bool => (int) $m->version_id === $version->id && in_array((int) $m->user_id, $registrationManagerIds, true),
                ),
                reviews: $version->readinessReviews->keyBy('item_key'),
            )];
        });
    }

    /**
     * Same all-or-nothing resolution as Version::availableScoreCategories()
     * (a Version's own categories if it has any, else its Event's defaults),
     * with factor counts attached — two queries for any number of Versions.
     *
     * @param  EloquentCollection<int, Version>  $versions
     * @return Collection<int, covariant Collection<int, ScoreCategory>> keyed by version id
     */
    private function rubrics(EloquentCollection $versions): Collection
    {
        $categories = ScoreCategory::query()
            ->where(fn ($query) => $query
                ->whereIn('version_id', $versions->modelKeys())
                ->orWhere(fn ($q) => $q->whereIn('event_id', $versions->pluck('event_id')->unique())->whereNull('version_id')))
            ->withCount('scoreFactors')
            ->orderBy('order_by')
            ->get();

        return $versions->mapWithKeys(function (Version $version) use ($categories): array {
            $own = $categories->filter(fn (ScoreCategory $c): bool => (int) $c->version_id === $version->id)->values();

            return [$version->id => $own->isNotEmpty()
                ? $own
                : $categories->filter(fn (ScoreCategory $c): bool => $c->version_id === null && (int) $c->event_id === $version->event_id)->values()];
        });
    }
}
