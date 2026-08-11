<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom\Reports;

use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Landing page for the Tab Room Reports sub-module (Tab Room Module.docx),
 * scoped separately from the Registration Manager Reporting Module's own
 * hub (App\Livewire\Events\Reports\Index) even though both follow the same
 * filter-preview-export convention — these five reports are
 * canManageTabRoomReports()-scoped (Tab Room Manager/Event Manager/Founder),
 * not canManageReports()-scoped (Registration/Co-Registration Manager), and
 * none of them are county-scoped the way every Registration Manager report is.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    public Version $version;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageTabRoomReports(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function render(): View
    {
        return view('livewire.events.tab-room.reports.index');
    }
}
