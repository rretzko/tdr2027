<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentHasActiveSchool
{
    public function handle(Request $request, Closure $next): Response
    {
        $student = $request->user()?->student;

        if ($student !== null && $student->current_school === null) {
            return redirect()->route('sfdi.school')->with('status', 'no-active-school');
        }

        return $next($request);
    }
}
