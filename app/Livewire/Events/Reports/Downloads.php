<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Reports → Downloads: CSV exports of the Version's owned data (Event/
 * Version, Schools, Teachers, Students, Candidates). Event Manager (and
 * Founder) only — not county-scoped, so Registration/Co-Registration
 * Managers are excluded.
 */
#[Layout('components.layouts.app')]
class Downloads extends Component
{
    public Version $version;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canDownloadVersionData(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function render(): View
    {
        return view('livewire.events.reports.downloads', [
            'downloads' => [
                ['topic' => 'event-version', 'name' => 'Event / Version', 'description' => 'Every Event and Version setting as key/value pairs, including dates, fees, counties, ensembles, obligations, timeslots, rooms and membership requirements.'],
                ['topic' => 'schools', 'name' => 'Schools', 'description' => 'One row per school of an invited teacher (or with a candidate).'],
                ['topic' => 'teachers', 'name' => 'Teachers', 'description' => 'One row per invited teacher at each active school, with contact, invitation, obligation and membership details.'],
                ['topic' => 'students', 'name' => 'Students', 'description' => 'Students of invited teachers at their active school, with address, teachers and guardians.'],
                ['topic' => 'candidates', 'name' => 'Candidates', 'description' => 'Every candidate with status, scores, result, fees/waivers/paid, and upload file names.'],
            ],
        ]);
    }
}
