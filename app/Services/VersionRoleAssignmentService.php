<?php

declare(strict_types=1);

namespace App\Services;

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

    public function __construct(private readonly VersionRoleService $versionRoles) {}

    /**
     * @return list<string>
     */
    public function assignableRoleNames(): array
    {
        return self::VERSION_SCOPED_ROLES;
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

    public function canAccessVersion(User $user, Version $version): bool
    {
        return $this->canManageEvent($user, $version->event)
            || $this->versionRoles->withVersion($version, fn (): bool => $user->hasAnyRole(self::VERSION_SCOPED_ROLES));
    }

    public function canManageVersionRoles(User $user, Version $version): bool
    {
        return $this->canManageEvent($user, $version->event);
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
        abort_unless(in_array($roleName, self::VERSION_SCOPED_ROLES, true), 400);

        $this->versionRoles->withVersion($version, function () use ($targetUser, $roleName): void {
            $targetUser->assignRole($roleName);
        });
    }

    public function revokeRole(User $actingUser, Version $version, User $targetUser, string $roleName): void
    {
        abort_unless($this->canManageVersionRoles($actingUser, $version), 403);
        abort_unless(in_array($roleName, self::VERSION_SCOPED_ROLES, true), 400);

        $this->versionRoles->withVersion($version, function () use ($targetUser, $roleName): void {
            $targetUser->removeRole($roleName);
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
