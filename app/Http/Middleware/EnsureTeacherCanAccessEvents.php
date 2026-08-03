<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\VersionRoleAssignmentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like EnsureTeacherHasActiveSchool, but also lets through a teacher who
 * holds a version-scoped role (Event Manager, Registration Manager, etc.)
 * on at least one Version — e.g. a retired teacher with no active school
 * of their own, brought in to administer a Version's Events section.
 */
class EnsureTeacherCanAccessEvents
{
    public function __construct(private readonly VersionRoleAssignmentService $versionRoles) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $teacher = $user?->teacher;

        if ($teacher !== null && ! $teacher->hasActiveSchool() && ! $this->versionRoles->canAccessEventsSection($user)) {
            return redirect()->route('schools.index')->with('status', 'no-active-school');
        }

        return $next($request);
    }
}
