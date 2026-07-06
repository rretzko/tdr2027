<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Version;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Centralizes switching the active Spatie "team" context, which in this app
 * is always a Version (see config/permission.php: models.team, column_names.
 * team_foreign_key). A null context means "global" — used for the identity
 * roles (Teacher, Student, Judge, Teacher_Supervisor, Founder/Admin) that
 * aren't scoped to any Version.
 */
final class VersionRoleService
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function activateVersion(Version|int $version): void
    {
        $this->registrar->setPermissionsTeamId($version instanceof Version ? $version->id : $version);
    }

    public function activateGlobal(): void
    {
        $this->registrar->setPermissionsTeamId(null);
    }

    public function currentVersionId(): int|string|null
    {
        return $this->registrar->getPermissionsTeamId();
    }

    /**
     * Run $callback with the given Version active as the permissions team
     * context, restoring whatever context was active beforehand.
     */
    public function withVersion(Version|int $version, Closure $callback): mixed
    {
        $previous = $this->currentVersionId();
        $this->activateVersion($version);

        try {
            return $callback();
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Run $callback with the global (null) permissions team context active,
     * restoring whatever context was active beforehand.
     */
    public function withGlobal(Closure $callback): mixed
    {
        $previous = $this->currentVersionId();
        $this->activateGlobal();

        try {
            return $callback();
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
        }
    }
}
