<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Candidate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the Event Manager Student Preview flow's restriction
 * (session('impersonation_scope') === 'event_manager_student_preview'):
 * the session may only reach the student-facing (sfdi.*) surface plus the
 * handful of routes shared by every portal, and sfdi.events.candidate is
 * further locked to the one Candidate the preview was started for — an
 * Event Manager previewing one student must not be able to browse to a
 * different real student's data by editing the URL. Every other route
 * (teacher/Event/Registrations/settings pages) is blocked outright. Other
 * impersonation scopes (none, or 'web_registration_manager') are untouched.
 *
 * The verification.* routes are allowed so that a student whose email
 * genuinely isn't verified sends the previewing Event Manager to the same
 * "verify your email" page the real student would hit (Laravel's `verified`
 * middleware redirects there on its own) — without this, that legitimate
 * redirect was itself blocked by this middleware, producing a dead-end 403
 * with no visible way back (2026-09-10 incident: the destination page never
 * rendered, so the "Return to Web Registration" banner never appeared
 * either — the session was only recoverable by clearing cookies). `logout`
 * is allowed for the same reason: always leave at least one guaranteed way
 * out that doesn't depend on this list being exhaustive.
 */
class RestrictEventManagerStudentImpersonation
{
    private const ALLOWED_ROUTES = [
        'dashboard', 'feedback.index', 'guides.show', 'founder.stop-impersonating',
        'verification.notice', 'verification.verify', 'verification.send', 'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (session('impersonation_scope') !== 'event_manager_student_preview') {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return $next($request);
        }

        $allowed = str_starts_with($routeName, 'sfdi.') || in_array($routeName, self::ALLOWED_ROUTES, true);

        abort_unless($allowed, 403);

        if ($routeName === 'sfdi.events.candidate') {
            $routeCandidate = $request->route('candidate');
            $candidateId = $routeCandidate instanceof Candidate ? $routeCandidate->id : (int) $routeCandidate;

            abort_if($candidateId !== (int) session('impersonation_candidate_id'), 403);
        }

        return $next($request);
    }
}
