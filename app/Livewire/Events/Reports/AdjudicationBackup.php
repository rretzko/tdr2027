<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Placeholder shell (resolved via clarifying question, event-version-
 * orientation.md §5.10): real nav entry, room list, and Paper/CSV/Checklist
 * links exist and are clickable, but since no room<->Candidate assignment
 * exists yet and no in-person audition is scheduled this season, the
 * generated files (AdjudicationBackupExportController) are placeholder
 * content, not wired to real per-candidate room data. Rooms are Version-wide
 * (no school/county relation), so this screen — unlike every other report —
 * is not filtered by the acting user's county scope.
 */
#[Layout('components.layouts.app')]
class AdjudicationBackup extends Component
{
    use ScopesReports;

    public Version $version;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        $this->authorizeReports($version, $roles);

        $this->version = $version;
    }

    public function render(): View
    {
        return view('livewire.events.reports.adjudication-backup', [
            'rooms' => $this->version->rooms,
        ]);
    }
}
