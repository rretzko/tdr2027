<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\VersionRoleService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;

/**
 * Octane isn't installed in this app, so Spatie's own Octane-reset listener
 * never fires. The equivalent risk here is a long-running `queue:work`
 * process reusing one PHP process across many jobs — reset the permissions
 * team context to global before each Artisan command and each queued job so
 * neither can inherit a version context left behind by a previous one.
 */
class VersionRoleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['events']->listen(CommandStarting::class, function (): void {
            $this->app->make(VersionRoleService::class)->activateGlobal();
        });

        $this->app['events']->listen(JobProcessing::class, function (): void {
            $this->app->make(VersionRoleService::class)->activateGlobal();
        });
    }
}
