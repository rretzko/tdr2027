<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeacherHasActiveSchool
{
    public function handle(Request $request, Closure $next): Response
    {
        $teacher = $request->user()?->teacher;

        if ($teacher !== null && ! $teacher->hasActiveSchool()) {
            return redirect()->route('schools.index')->with('status', 'no-active-school');
        }

        return $next($request);
    }
}
