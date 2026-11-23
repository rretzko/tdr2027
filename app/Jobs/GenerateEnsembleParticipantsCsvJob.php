<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Models\Version;
use App\Services\EnsembleParticipantsCsvService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds the Version's Ensemble Participants CSVs after Close Audition,
 * off the request.
 */
class GenerateEnsembleParticipantsCsvJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(
        private readonly int $versionId,
        private readonly ?int $generatedByUserId,
    ) {}

    public function handle(EnsembleParticipantsCsvService $csvs): void
    {
        $version = Version::find($this->versionId);

        if ($version === null) {
            return;
        }

        $csvs->generateAll($version, $this->generatedByUserId === null ? null : User::find($this->generatedByUserId));
    }
}
