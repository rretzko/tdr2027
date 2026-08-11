<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom\Reports;

use App\Models\Ensemble;
use App\Models\Version;
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tab Room Module.docx's "Ensemble Participation" report: every accepted
 * member of one Ensemble, with contact info for rehearsal/logistics use.
 */
#[Layout('components.layouts.app')]
class EnsembleParticipation extends Component
{
    public Version $version;

    #[Url]
    public ?int $ensembleId = null;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageTabRoomReports(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function render(TabRoomReportService $reports, EnsembleCutoffService $cutoffs): View
    {
        $ensemble = $this->ensembleId !== null ? Ensemble::find($this->ensembleId) : null;

        return view('livewire.events.tab-room.reports.ensemble-participation', [
            'ensembles' => $this->version->ensembleOrder->map(fn ($order) => $order->ensemble),
            'rows' => $ensemble !== null ? $reports->ensembleParticipationRows($this->version, $ensemble, $cutoffs) : collect(),
        ]);
    }
}
