<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * StudentFolder.info's app-name/title mirrors TheDirectorsRoom.com's own
 * environment-suffixed APP_NAME (e.g. "TDR<dev>" locally, "TDR" in
 * production) rather than a hardcoded string, so it stays in sync with
 * whatever environment suffix TDR's own title carries.
 */
final class PortalBranding
{
    public static function appName(bool $sfdi = false): string
    {
        $name = (string) config('app.name');

        return $sfdi ? Str::replace('TDR', 'SF', $name) : $name;
    }
}
