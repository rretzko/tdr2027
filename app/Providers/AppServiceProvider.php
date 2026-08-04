<?php

namespace App\Providers;

use App\Models\Pivots\SchoolStudent;
use App\Models\User;
use App\Observers\SchoolStudentObserver;
use App\Observers\UserObserver;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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

        // Every datetime is stored/computed in UTC (config('app.timezone')).
        // forDisplay() is the one place that converts to config('app.display_timezone')
        // for showing to a user — never store or compare against its result.
        Carbon::macro('forDisplay', function (): CarbonInterface {
            /** @var CarbonInterface $this */
            return $this->copy()->timezone(config('app.display_timezone'));
        });
    }
}
