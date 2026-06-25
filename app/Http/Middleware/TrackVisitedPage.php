<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\FastPass;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackVisitedPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        $routeName = $request->route()?->getName();
        $label = $routeName !== null ? (FastPass::activeRouteMap()[$routeName] ?? null) : null;

        if ($user !== null && $label !== null && $response->isSuccessful()) {
            FastPass::record($user, $routeName, $label);
        }

        return $response;
    }
}
