<?php

namespace App\Providers;

use App\Models\Pivots\SchoolStudent;
use App\Models\User;
use App\Observers\SchoolStudentObserver;
use App\Observers\UserObserver;
use Carbon\CarbonInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);
        SchoolStudent::observe(SchoolStudentObserver::class);

        // Vendor e-payment webhooks (epayment-integration.md §2.4) — keyed by
        // IP, not per-user, since these are unauthenticated vendor callbacks.
        // Higher than the social-callback limiter (FortifyServiceProvider):
        // a real vendor can burst several events per transaction.
        RateLimiter::for('payment-webhooks', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Every datetime is stored/computed in UTC (config('app.timezone')).
        // forDisplay() is the one place that converts to config('app.display_timezone')
        // for showing to a user — never store or compare against its result.
        Carbon::macro('forDisplay', function (): CarbonInterface {
            /** @var CarbonInterface $this */
            return $this->copy()->timezone(config('app.display_timezone'));
        });
    }
}
