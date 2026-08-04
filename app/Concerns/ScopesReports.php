<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;

/**
 * Shared authorization + county-scoping for every Registration Manager
 * Reporting Module screen (event-version-orientation.md §5.10). Every
 * report is scoped to *.version_id and to the acting user's county access —
 * null from reportCountyIds() means unrestricted (every county).
 */
trait ScopesReports
{
    /**
     * @var list<int>|null
     */
    public ?array $reportCountyIds = null;

    public function authorizeReports(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $this->reportCountyIds = $roles->reportCountyIds(Auth::user(), $version);
    }

    /**
     * Whether a given county id is within the acting user's scope — always
     * true when reportCountyIds is null (unrestricted).
     */
    protected function withinReportScope(?int $countyId): bool
    {
        if ($this->reportCountyIds === null) {
            return true;
        }

        return $countyId !== null && in_array($countyId, $this->reportCountyIds, true);
    }
}
