<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom\Reports;

class CombinedAuditionScoresPublic extends CombinedAuditionScores
{
    public function confidential(): bool
    {
        return false;
    }

    public function exportRouteName(): string
    {
        return 'events.versions.tab-room.reports.combined-scores-public.export';
    }

    public function heading(): string
    {
        return 'Combined Audition Scores (Public)';
    }
}
