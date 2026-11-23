<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Version;
use App\Services\EnsembleParticipantsCsvService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Reports → Downloads: CSV exports of the Version's owned data (Event/
 * Version, Schools, Teachers, Students, Candidates). Event Manager (and
 * Founder) only — not county-scoped, so Registration/Co-Registration
 * Managers are excluded. Also the Ensemble Participants card: one stored
 * CSV per Ensemble of its accepted candidates (EnsembleParticipantsCsvService),
 * built at Close Audition and rebuilt here on demand.
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

    /**
     * Rebuilds every Ensemble's participants CSV from current data, e.g.
     * after a decline or a corrected phone number.
     */
    public function regenerateEnsembleParticipants(VersionRoleAssignmentService $roles, EnsembleParticipantsCsvService $csvs): void
    {
        abort_unless($roles->canDownloadVersionData(Auth::user(), $this->version), 403);

        $accepted = Candidate::where('version_id', $this->version->id)->where('status', CandidateStatus::Accepted->value)->count();

        if ($accepted === 0) {
            Flux::toast(text: "No candidates in {$this->version->name} are accepted yet, so there's nothing to put in the files. They're built when the audition is closed.", variant: 'warning');

            return;
        }

        $exports = $csvs->generateAll($this->version, Auth::user());
        $files = count($exports);

        Flux::toast(text: "Rebuilt {$files} Ensemble Participants ".str('file')->plural($files)." for {$this->version->name} ({$accepted} accepted candidates).", variant: 'success');
    }

    public function render(EnsembleParticipantsCsvService $csvs): View
    {
        $exports = $csvs->exportsFor($this->version);

        return view('livewire.events.reports.downloads', [
            'ensembleParticipants' => $this->version->ensembleOrder()->with('ensemble')->get()
                ->map(fn ($order): array => ['ensemble' => $order->ensemble, 'export' => $exports->get($order->ensemble_id)])
                ->filter(fn (array $item): bool => $item['ensemble'] instanceof Ensemble)
                ->values(),
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
