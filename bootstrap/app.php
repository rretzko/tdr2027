<?php

use App\Http\Middleware\EnsureTeacherCanAccessEvents;
use App\Http\Middleware\EnsureTeacherHasActiveSchool;
use App\Http\Middleware\EnsureTeacherOnboardingComplete;
use App\Http\Middleware\EnsureUserIsFounder;
use App\Http\Middleware\ResetVersionRoleContext;
use App\Http\Middleware\RestrictWebRegistrationImpersonation;
use App\Http\Middleware\TrackVisitedPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'onboarding.complete' => EnsureTeacherOnboardingComplete::class,
            'has.active.school' => EnsureTeacherHasActiveSchool::class,
            'can.access.events' => EnsureTeacherCanAccessEvents::class,
            'founder' => EnsureUserIsFounder::class,
        ]);

        $middleware->prepend(ResetVersionRoleContext::class);

        $middleware->web(append: [TrackVisitedPage::class, RestrictWebRegistrationImpersonation::class]);

        // Vendor e-payment webhooks (epayment-integration.md §2.4) — a
        // vendor server has no CSRF token to send; each gateway verifies its
        // own signature instead (SquarePaymentGateway::verifyWebhookSignature()).
        $middleware->validateCsrfTokens(except: ['webhooks/payments/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
