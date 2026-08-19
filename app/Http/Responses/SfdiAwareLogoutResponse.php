<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a logged-out student back to the SFDI splash page (`sfdi.welcome`)
 * instead of the TDR one (`/`) — Fortify's default LogoutResponse always
 * redirects to '/', with no concept of a StudentFolder.info/TheDirectorsRoom
 * split.
 *
 * Auth::user() is already null by the time this resolves — Fortify's
 * AuthenticatedSessionController::destroy() logs the user out and
 * invalidates the session before resolving this response. FortifyServiceProvider
 * captures the role earlier via an Illuminate\Auth\Events\Logout listener
 * (which still has the user) and stashes it on the request's attribute bag,
 * which — unlike the session — isn't cleared by session()->invalidate().
 */
class SfdiAwareLogoutResponse implements LogoutResponseContract
{
    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect($request->attributes->get('logged_out_as_sfdi_student') === true
            ? route('sfdi.welcome')
            : '/');
    }
}
