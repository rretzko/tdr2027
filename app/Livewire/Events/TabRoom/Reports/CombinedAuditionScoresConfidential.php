<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom\Reports;

class CombinedAuditionScoresConfidential extends CombinedAuditionScores
{
    public function confidential(): bool
    {
        return true;
    }

    public function exportRouteName(): string
    {
        return 'events.versions.tab-room.reports.combined-scores-confidential.export';
    }

    public function heading(): string
    {
        return 'Combined Audition Scores (Confidential)';
    }
}
