<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Version;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the two restrictions the Web Registration Manager Module
 * (event-version-orientation.md §5.11) places on a Web-Registration-
 * Manager-initiated impersonation session
 * (session('impersonation_scope') === 'web_registration_manager'): the
 * teacher account/profile routes are blocked outright, and any
 * registrations.* route bound to a {version} other than the locked
 * impersonation_version_id is blocked too. Founder-initiated impersonation
 * (no impersonation_scope set) is untouched — it keeps its existing
 * unrestricted access.
 */
class RestrictWebRegistrationImpersonation
{
    private const BLOCKED_ROUTES = ['settings.profile', 'settings.password'];

    public function handle(Request $request, Closure $next): Response
    {
        if (session('impersonation_scope') !== 'web_registration_manager') {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return $next($request);
        }

        abort_if(in_array($routeName, self::BLOCKED_ROUTES, true), 403);

        if (str_starts_with($routeName, 'registrations.')) {
            $routeVersion = $request->route('version');

            if ($routeVersion !== null) {
                $versionId = $routeVersion instanceof Version ? $routeVersion->id : (int) $routeVersion;

                abort_if($versionId !== (int) session('impersonation_version_id'), 403);
            }
        }

        return $next($request);
    }
}
