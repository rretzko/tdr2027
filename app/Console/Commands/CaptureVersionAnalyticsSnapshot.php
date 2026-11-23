<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Version;
use App\Services\VersionAnalyticsSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Captures (or recaptures) a closed Version's analytics snapshot by hand:
 * the backfill for Versions closed before snapshots existed (v93), and a
 * retry if the Close-time job failed. Stamped with the Version's
 * results_released_at so the adjudication window reads as it did at Close.
 */
class CaptureVersionAnalyticsSnapshot extends Command
{
    protected $signature = 'analytics:snapshot {version : The Version id}';

    protected $description = 'Capture the end-of-cycle analytics snapshot for a closed Version.';

    public function handle(VersionAnalyticsSnapshotService $snapshots): int
    {
        $version = Version::find((int) $this->argument('version'));

        if ($version === null) {
            $this->error('Version not found.');

            return self::FAILURE;
        }

        if (! $version->isClosed()) {
            $this->error("{$version->name} isn't closed. Snapshots are captured at Close Audition.");

            return self::FAILURE;
        }

        $at = $version->results_released_at === null ? Carbon::now() : Carbon::parse($version->results_released_at);
        $snapshot = $snapshots->capture($version, null, $at);

        $this->info("Captured analytics snapshot for {$version->name} (as of {$snapshot->captured_at->toDateTimeString()} UTC).");

        return self::SUCCESS;
    }
}
