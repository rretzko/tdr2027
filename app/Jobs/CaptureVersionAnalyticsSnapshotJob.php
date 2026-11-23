<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Models\Version;
use App\Services\VersionAnalyticsSnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Captures a Version's analytics snapshot after Close Audition, off the
 * request: AdjudicationProgressService alone reads every tracked playback
 * event for the Version (about a second on a large Version). Stamped with
 * the Close time, not the time the worker picks it up, so the adjudication
 * window reads as it did at Close.
 */
class CaptureVersionAnalyticsSnapshotJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(
        private readonly int $versionId,
        private readonly ?int $capturedByUserId,
        private readonly string $closedAt,
    ) {}

    public function handle(VersionAnalyticsSnapshotService $snapshots): void
    {
        $version = Version::find($this->versionId);

        if ($version === null) {
            return;
        }

        $snapshots->capture(
            $version,
            $this->capturedByUserId === null ? null : User::find($this->capturedByUserId),
            Carbon::parse($this->closedAt),
        );
    }
}
