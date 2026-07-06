<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\VersionRoleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures every request starts with the global (null) permissions team
 * context, so role checks for identity roles (Teacher, Student, etc.) never
 * inherit a version context left behind by request-specific logic. Route
 * middleware that needs a Version-scoped check activates it deliberately.
 */
class ResetVersionRoleContext
{
    public function __construct(private readonly VersionRoleService $versionRoles) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->versionRoles->activateGlobal();

        return $next($request);
    }
}
