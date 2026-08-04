<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoRegistrationManagerCounty;
use App\Models\Event;
use App\Models\User;
use App\Models\Version;
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

    public function __construct(private readonly VersionRoleService $versionRoles) {}

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
        return $this->canManageEvent($user, $event) || $this->holdsAnyVersionScopedRoleForEvent($user, $event);
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
     * granted the role on didn't exist a moment ago.
     */
    public function bootstrapEventManager(User $user, Version $version): void
    {
        $this->versionRoles->withVersion($version, function () use ($user): void {
            $user->assignRole('Event Manager');
        });
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
        $versionIds = $this->matchingVersionIds($user, self::VERSION_SCOPED_ROLES);

        if ($versionIds->isEmpty()) {
            return [];
        }

        return Version::whereIn('id', $versionIds)->pluck('event_id')->unique()->values()->all();
    }

    public function assignRole(User $actingUser, Version $version, User $targetUser, string $roleName): void
    {
        abort_unless($this->canManageVersionRoles($actingUser, $version), 403);
        abort_unless(in_array($roleName, self::GENERAL_ROLES_TAB_ROLES, true), 400);

        $this->versionRoles->withVersion($version, function () use ($targetUser, $roleName): void {
            $targetUser->assignRole($roleName);
        });
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

        DB::transaction(function () use ($version, $targetUser, $countyIds): void {
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
     * @param  list<string>  $roleNames
     */
    private function holdsRoleForEvent(User $user, Event $event, array $roleNames): bool
    {
        return $this->matchingVersionIds($user, $roleNames, $event)->isNotEmpty();
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
