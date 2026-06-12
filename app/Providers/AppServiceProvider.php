<?php

namespace App\Providers;

use App\Models\Pivots\SchoolStudent;
use App\Models\User;
use App\Observers\SchoolStudentObserver;
use App\Observers\UserObserver;
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
    }
}
