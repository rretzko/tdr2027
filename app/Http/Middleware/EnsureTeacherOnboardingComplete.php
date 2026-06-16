<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeacherOnboardingComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $teacher = $request->user()?->teacher;

        if ($teacher !== null && $teacher->onboarding_completed_at === null) {
            return redirect()->route('teacher.onboarding');
        }

        return $next($request);
    }
}
