<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom;

use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Landing page for the Tab Room Module (Tab Room Module.docx) — links to
 * its five sub-modules. Built incrementally: Add/Edit Scores and
 * Adjudication Tracking ship first (Phase 1); Ensemble Cut-offs, Reports,
 * and Close Audition follow in later phases (see
 * docs/plans/event-version-orientation.md §7.3-§7.7).
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    public Version $version;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageTabRoom(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function render(): View
    {
        return view('livewire.events.tab-room.index', [
            'hasCutoffStrategy' => $this->version->getRawOriginal('cutoff_strategy') !== null,
        ]);
    }
}
