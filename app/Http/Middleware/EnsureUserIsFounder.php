<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnsureUserIsFounder
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isFounder()) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
