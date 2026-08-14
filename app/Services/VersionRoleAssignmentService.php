<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\VersionDateType;
use App\Enums\VersionInvitationStatus;
use App\Models\CoRegistrationManagerCounty;
use App\Models\Event;
use App\Models\RoomJudge;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionInvitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Support\Config;

/**
 * Authorization decisions and role-assignment operations for the 6
 * version-scoped roles. "Event Manager" is treated as authority over the
 * whole Event — computed by checking whether the user holds that role on
 * *any* of the Event's Versions — since roles are only ever stored scoped to
 * a version_id and a brand-new Event has none yet. This is what lets a
 * newly-assigned Event Manager create and manage subsequent Versions of the
 * same Event without Founder involvement.
 */
final class VersionRoleAssignmentService
{
    /**
     * @var list<string>
     */
    private const VERSION_SCOPED_ROLES = [
        'Event Manager',
        'Registration Manager',
        'Co-Registration Manager',
        'Web Registration Manager',
        'Tab Room Manager',
        'Rehearsal Manager',
    ];

    /**
     * Assignable from the generic Configure → Roles tab. Co-Registration
     * Manager is deliberately excluded — it's assigned only via the
     * dedicated Co-Registration Managers screen (see
     * canManageCoRegistrationManagers() below), where it's always paired
     * with a county assignment; the two must never diverge.
     *
     * @var list<string>
     */
    private const GENERAL_ROLES_TAB_ROLES = [
        'Event Manager',
        'Registration Manager',
        'Web Registration Manager',
        'Tab Room Manager',
        'Rehearsal Manager',
    ];

    /**
     * Memoized per user id — adjudicatableVersionFor() calls canAdjudicate()
     * once per sibling Version of an Event, and canAdjudicate() calls
     * judgeVersionIds() every time; without memoizing, looping Events on the
     * index page would re-run the same RoomJudge query repeatedly within one
     * request.
     *
     * @var array<int, Collection<int, int>>
     */
    private array $judgeVersionIdsByUser = [];

    public function __construct(
        private readonly VersionRoleService $versionRoles,
        private readonly AutoEnrollmentService $autoEnrollment,
    ) {}

    /**
     * @return list<string>
     */
    public function assignableRoleNames(): array
    {
        return self::GENERAL_ROLES_TAB_ROLES;
    }

    public function isEventManagerForEvent(User $user, Event $event): bool
    {
        return $this->holdsRoleForEvent($user, $event, ['Event Manager']);
    }

    public function holdsAnyVersionScopedRoleForEvent(User $user, Event $event): bool
    {
        return $this->holdsRoleForEvent($user, $event, self::VERSION_SCOPED_ROLES);
    }

    public function canManageEvent(User $user, Event $event): bool
    {
        return $user->isFounder() || $this->isEventManagerForEvent($user, $event);
    }

    public function canViewEvent(User $user, Event $event): bool
    {
        return $this->canManageEvent($user, $event)
            || $this->holdsAnyVersionScopedRoleForEvent($user, $event)
            || $this->isJudgeForEvent($user, $event);
    }

    /**
     * A RoomJudge assignment on any Version of the Event grants view-only
     * visibility — judges are not one of the six Spatie
     * VERSION_SCOPED_ROLES, so they'd otherwise be invisible to every method
     * in this class (see event-version-orientation.md §5.9).
     */
    public function isJudgeForEvent(User $user, Event $event): bool
    {
        return $event->versions()->whereIn('id', $this->judgeVersionIds($user))->exists();
    }

    /**
     * Whether the Events nav link/section should be shown at all — Founder,
     * or holding any of the six version-scoped roles on at least one event.
     */
    public function canAccessEventsSection(User $user): bool
    {
        return $user->isFounder() || $this->eventIdsVisibleTo($user) !== [];
    }

    /**
     * Grants "Event Manager" on a brand-new Version with no prior authorization
     * check — used only by the self-service event-creation flow, where the
     * creator has no standing role yet because the Version they're being
     * granted the role on didn't exist a moment ago. Also backfills
     * enrollment for every already-invited teacher (see
     * AutoEnrollmentService::enrollAllInvitedTeachersForVersion()), so a
     * version-scoped manager can review real Candidate Management data even
     * while the Version is still Sandbox, ahead of going Active.
     */
    public function bootstrapEventManager(User $user, Version $version): void
    {
        $this->versionRoles->withVersion($version, function () use ($user): void {
            $user->assignRole('Event Manager');
        });

        $this->inviteToVersion($user, $version, $user);
        $this->autoEnrollment->enrollAllInvitedTeachersForVersion($version);
    }

    public function canManageVersionRoles(User $user, Version $version): bool
    {
        return $this->canManageEvent($user, $version->event);
    }

    /**
     * Access to the audition-environment configuration screens (Rooms,
     * Scoring Rubric): Founder or Event-wide Event Manager, or Registration
     * Manager/Co-Registration Manager held specifically on this Version —
     * per-Version, not Event-wide, unlike Event Manager above.
     */
    public function canManageAuditionEnvironment(User $user, Version $version): bool
    {
        return $this->canManageEvent($user, $version->event)
            || $this->versionRoles->withVersion(
                $version,
                function () use ($user): bool {
                    // See canAccessVersion() above — same stale-relation-cache hazard.
                    $user->unsetRelation('roles');

                    return $user->hasAnyRole(['Registration Manager', 'Co-Registration Manager']);
                },
            );
    }

    /**
     * Access to the Co-Registration Managers screen: Founder or Event-wide
     * Event Manager, or "Registration Manager" held specifically on this
     * Version. Deliberately narrower than canManageAuditionEnvironment()
     * above — a Co-Registration Manager cannot grant the Co-Registration
     * Manager role to someone else, so it is not included here.
     */
    public function canManageCoRegistrationManagers(User $user, Version $version): bool
    {
        return $this->canManageEvent($user, $version->event)
            || $this->versionRoles->withVersion(
                $version,
                function () use ($user): bool {
                    // See canManageAuditionEnvironment() above — same stale-relation-cache hazard.
                    $user->unsetRelation('roles');

                    return $user->hasRole('Registration Manager');
                },
            );
    }

    /**
     * Access to the Registration Manager Reporting Module (§5.10 of
     * event-version-orientation.md): the exact same gate as
     * canManageAuditionEnvironment() above. Kept as its own named method —
     * rather than call sites reusing canManageAuditionEnvironment()
     * directly — so the two screens' authorization can diverge later
     * without a misleading method name; today they resolve identically.
     */
    public function canManageReports(User $user, Version $version): bool
    {
        return $this->canManageAuditionEnvironment($user, $version);
    }

    /**
     * Access to the Tab Room Module (Add/Edit Scores, Adjudication Tracking,
     * Ensemble Cut-offs, Reports, Close Audition — Tab Room Module.docx):
     * Founder or Event-wide Event Manager, or "Tab Room Manager" held
     * specifically on this Version. Deliberately its own gate rather than
     * folded into canManageAuditionEnvironment() above — a Registration/
     * Co-Registration Manager has no business overriding judge scores or
     * closing an audition, and a Tab Room Manager has no business in the
     * Rooms/Scoring Rubric configuration screens.
     */
    public function canManageTabRoom(User $user, Version $version): bool
    {
        return $this->canManageEvent($user, $version->event)
            || $this->versionRoles->withVersion(
                $version,
                function () use ($user): bool {
                    // See canManageAuditionEnvironment() above — same stale-relation-cache hazard.
                    $user->unsetRelation('roles');

                    return $user->hasRole('Tab Room Manager');
                },
            );
    }

    /**
     * Access to the Tab Room Reports sub-module (Audition Scores, Combined
     * Audition Scores confidential/public, Ensemble Participation, Student
     * Seniority — Tab Room Module.docx): the exact same gate as
     * canManageTabRoom() above. Kept as its own named method — rather than
     * call sites reusing canManageTabRoom() directly — so the two can
     * diverge later without a misleading method name, mirroring how
     * canManageReports() sits next to canManageAuditionEnvironment(); today
     * they resolve identically.
     */
    public function canManageTabRoomReports(User $user, Version $version): bool
    {
        return $this->canManageTabRoom($user, $version);
    }

    /**
     * Access to the Web Registration Manager Module (impersonate an invited
     * teacher, transfer students between teachers — §5.11 of
     * event-version-orientation.md): Founder or Event-wide Event Manager, or
     * "Web Registration Manager" held specifically on this Version.
     * Deliberately excludes Registration Manager/Co-Registration Manager,
     * unlike canManageAuditionEnvironment()/canManageReports() — the source
     * doc names only this one role.
     */
    public function canManageWebRegistration(User $user, Version $version): bool
    {
        return $this->canManageEvent($user, $version->event)
            || $this->versionRoles->withVersion(
                $version,
                function () use ($user): bool {
                    // See canManageAuditionEnvironment() above — same stale-relation-cache hazard.
                    $user->unsetRelation('roles');

                    return $user->hasRole('Web Registration Manager');
                },
            );
    }

    /**
     * The county scope a user's reports are restricted to for this Version.
     * Null means unrestricted (every county) — Founder, Event-wide Event
     * Manager, or Registration Manager held specifically on this Version. A
     * Co-Registration Manager instead gets exactly their assigned
     * co_registration_manager_counties rows for this Version (possibly
     * empty, if somehow assigned the role with no counties). Callers should
     * gate on canManageReports() first — a user holding neither role gets
     * the fail-closed empty-list result, not null.
     *
     * @return list<int>|null
     */
    public function reportCountyIds(User $user, Version $version): ?array
    {
        if ($this->canManageEvent($user, $version->event)) {
            return null;
        }

        return $this->versionRoles->withVersion(
            $version,
            function () use ($user, $version): ?array {
                // See canManageAuditionEnvironment() above — same stale-relation-cache hazard.
                $user->unsetRelation('roles');

                if ($user->hasRole('Registration Manager')) {
                    return null;
                }

                if ($user->hasRole('Co-Registration Manager')) {
                    return CoRegistrationManagerCounty::query()
                        ->where('version_id', $version->id)
                        ->where('user_id', $user->id)
                        ->pluck('county_id')
                        ->all();
                }

                return [];
            },
        );
    }

    /**
     * Distinct users holding "Event Manager" on any Version of the Event —
     * the notification list for Event-level emails (e.g. §5.8's invitation
     * request notice), since the role itself is only ever stored scoped to
     * a version_id.
     *
     * @return Collection<int, User>
     */
    public function eventManagersForEvent(Event $event): Collection
    {
        return $event->versions
            ->flatMap(fn (Version $version): Collection => $this->assignmentsForVersion($version)->get('Event Manager') ?? collect())
            ->unique('id')
            ->values();
    }

    /**
     * @return list<int>
     */
    public function eventIdsVisibleTo(User $user): array
    {
        $versionIds = $this->matchingVersionIds($user, self::VERSION_SCOPED_ROLES)
            ->merge($this->judgeVersionIds($user))
            ->unique();

        if ($versionIds->isEmpty()) {
            return [];
        }

        return Version::whereIn('id', $versionIds)->pluck('event_id')->unique()->values()->all();
    }

    /**
     * @return list<int>
     */
    public function judgeEventIds(User $user): array
    {
        $versionIds = $this->judgeVersionIds($user);

        if ($versionIds->isEmpty()) {
            return [];
        }

        return Version::whereIn('id', $versionIds)->pluck('event_id')->unique()->values()->all();
    }

    /**
     * Whether $user may open the Adjudication page for this specific
     * Version: the Version must be active, $user must hold a RoomJudge
     * assignment on *this* Version (not merely a sibling one — unlike
     * isJudgeForEvent()'s event-wide leniency, the adjudication window is
     * per-Version), now() must fall inside that Version's
     * date_type=adjudication window, and $user must not currently be an
     * enrolled Student (a candidate shouldn't adjudicate their own
     * competition, even if erroneously also assigned as a RoomJudge).
     */
    public function canAdjudicate(User $user, Version $version): bool
    {
        return $version->getRawOriginal('status') === EventStatus::Active->value
            && $this->judgeVersionIds($user)->contains($version->id)
            && $this->isWithinAdjudicationWindow($version)
            && ! $this->isCurrentStudent($user);
    }

    /**
     * The single Version of $event that $user may currently adjudicate, or
     * null if none qualify. Assumes at most one qualifying Version per
     * Event at a time; if more than one somehow qualifies, the first match
     * wins.
     */
    public function adjudicatableVersionFor(User $user, Event $event): ?Version
    {
        return $event->versions->first(fn (Version $version): bool => $this->canAdjudicate($user, $version));
    }

    public function assignRole(User $actingUser, Version $version, User $targetUser, string $roleName): void
    {
        abort_unless($this->canManageVersionRoles($actingUser, $version), 403);
        abort_unless(in_array($roleName, self::GENERAL_ROLES_TAB_ROLES, true), 400);

        $this->versionRoles->withVersion($version, function () use ($targetUser, $roleName): void {
            $targetUser->assignRole($roleName);
        });

        $this->inviteToVersion($targetUser, $version, $actingUser);
        $this->autoEnrollment->enrollAllInvitedTeachersForVersion($version);
    }

    public function revokeRole(User $actingUser, Version $version, User $targetUser, string $roleName): void
    {
        abort_unless($this->canManageVersionRoles($actingUser, $version), 403);
        abort_unless(in_array($roleName, self::GENERAL_ROLES_TAB_ROLES, true), 400);

        $this->versionRoles->withVersion($version, function () use ($targetUser, $roleName): void {
            $targetUser->removeRole($roleName);
        });
    }

    /**
     * Assigns "Co-Registration Manager" and (re)sets the target's county
     * assignment in one operation — a Co-Registration Manager without
     * counties has no meaningful scope, so the two are never split across
     * separate calls. Replaces any counties previously assigned to this
     * user on this Version.
     *
     * @param  list<int>  $countyIds  Caller must have already validated this
     *                                as a subset of the Version's own
     *                                counties() and free of any county
     *                                already claimed by another manager on
     *                                this Version — the unique constraint on
     *                                co_registration_manager_counties is the
     *                                final backstop, not the primary check.
     */
    public function assignCoRegistrationManager(User $actingUser, Version $version, User $targetUser, array $countyIds): void
    {
        abort_unless($this->canManageCoRegistrationManagers($actingUser, $version), 403);

        DB::transaction(function () use ($version, $targetUser, $countyIds, $actingUser): void {
            $this->versionRoles->withVersion($version, function () use ($targetUser): void {
                $targetUser->assignRole('Co-Registration Manager');
            });

            CoRegistrationManagerCounty::where('version_id', $version->id)
                ->where('user_id', $targetUser->id)
                ->delete();

            foreach ($countyIds as $countyId) {
                CoRegistrationManagerCounty::create([
                    'version_id' => $version->id,
                    'user_id' => $targetUser->id,
                    'county_id' => $countyId,
                ]);
            }

            $this->inviteToVersion($targetUser, $version, $actingUser);
            $this->autoEnrollment->enrollAllInvitedTeachersForVersion($version);
        });
    }

    public function revokeCoRegistrationManager(User $actingUser, Version $version, User $targetUser): void
    {
        abort_unless($this->canManageCoRegistrationManagers($actingUser, $version), 403);

        DB::transaction(function () use ($version, $targetUser): void {
            $this->versionRoles->withVersion($version, function () use ($targetUser): void {
                $targetUser->removeRole('Co-Registration Manager');
            });

            CoRegistrationManagerCounty::where('version_id', $version->id)
                ->where('user_id', $targetUser->id)
                ->delete();
        });
    }

    /**
     * @return Collection<string, Collection<int, User>>
     */
    public function assignmentsForVersion(Version $version): Collection
    {
        return $this->versionRoles->withVersion(
            $version,
            fn (): Collection => collect(self::VERSION_SCOPED_ROLES)
                ->mapWithKeys(fn (string $role): array => [$role => User::role($role)->get()]),
        );
    }

    /**
     * Auto-invites a version-scoped role assignee to the Version itself —
     * anyone holding one of the six roles needs Registrations access to the
     * same Version they're managing, without an Event Manager having to
     * separately invite them via the teacher-eligibility screen (§5.4).
     * Silently no-ops if the target has no Teacher record yet (e.g. a
     * staff-only account that never completed teacher onboarding).
     * firstOrCreate() rather than create() because the
     * unique(version_id, teacher_id) constraint would otherwise throw when a
     * second role is granted to an already-invited teacher.
     */
    private function inviteToVersion(User $targetUser, Version $version, User $invitedByUser): void
    {
        $teacher = $targetUser->teacher;

        if ($teacher === null) {
            return;
        }

        VersionInvitation::firstOrCreate(
            ['version_id' => $version->id, 'teacher_id' => $teacher->id],
            [
                'status' => VersionInvitationStatus::Invited->value,
                'invited_at' => now(),
                'invited_by_user_id' => $invitedByUser->id,
            ],
        );
    }

    /**
     * @param  list<string>  $roleNames
     */
    private function holdsRoleForEvent(User $user, Event $event, array $roleNames): bool
    {
        return $this->matchingVersionIds($user, $roleNames, $event)->isNotEmpty();
    }

    /**
     * @return Collection<int, int>
     */
    private function judgeVersionIds(User $user): Collection
    {
        return $this->judgeVersionIdsByUser[$user->id] ??= RoomJudge::where('user_id', $user->id)->pluck('version_id')->unique();
    }

    private function isCurrentStudent(User $user): bool
    {
        return $user->student !== null && $user->student->isCurrentlyEnrolled();
    }

    private function isWithinAdjudicationWindow(Version $version): bool
    {
        return VersionDate::where('version_id', $version->id)
            ->where('date_type', VersionDateType::Adjudication)
            ->where('start_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->exists();
    }

    /**
     * @param  list<string>  $roleNames
     * @return Collection<int, int>
     */
    private function matchingVersionIds(User $user, array $roleNames, ?Event $event = null): Collection
    {
        $columnNames = config('permission.column_names');
        $rolePivotKey = $columnNames['role_pivot_key'] ?? 'role_id';
        $teamForeignKey = Config::teamForeignKey();
        $morphKey = Config::morphKey();

        $query = DB::table(Config::modelHasRolesTable())
            ->join(Config::rolesTable(), Config::rolesTable().'.id', '=', Config::modelHasRolesTable().'.'.$rolePivotKey)
            ->where(Config::rolesTable().'.guard_name', 'web')
            ->whereIn(Config::rolesTable().'.name', $roleNames)
            ->where(Config::modelHasRolesTable().'.'.$morphKey, $user->id)
            ->where(Config::modelHasRolesTable().'.model_type', $user->getMorphClass())
            ->whereNotNull(Config::modelHasRolesTable().'.'.$teamForeignKey);

        if ($event !== null) {
            $query->whereIn(Config::modelHasRolesTable().'.'.$teamForeignKey, $event->versions()->pluck('id'));
        }

        return $query->pluck(Config::modelHasRolesTable().'.'.$teamForeignKey)->unique();
    }
}
