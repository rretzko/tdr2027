<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom\Reports;

use App\Models\Ensemble;
use App\Models\Version;
use App\Services\TabRoomReportService;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tab Room Module.docx's "Student Seniority" report: for each currently
 * accepted member of one Ensemble, whether they were also accepted in each
 * sibling Version of the same Event, grid by senior_class_of year.
 */
#[Layout('components.layouts.app')]
class StudentSeniority extends Component
{
    public Version $version;

    #[Url]
    public ?int $ensembleId = null;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageTabRoomReports(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function render(TabRoomReportService $reports): View
    {
        $ensemble = $this->ensembleId !== null ? Ensemble::find($this->ensembleId) : null;
        $rows = $ensemble !== null ? $reports->studentSeniorityRows($this->version, $ensemble) : collect();

        return view('livewire.events.tab-room.reports.student-seniority', [
            'ensembles' => $this->version->ensembleOrder->map(fn ($order) => $order->ensemble),
            'years' => $rows->isNotEmpty() ? array_keys($rows->first()['years']) : [],
            'rows' => $rows,
        ]);
    }
}
