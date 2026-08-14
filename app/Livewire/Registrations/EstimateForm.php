<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\GuardsAcceptedObligations;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Placeholder only — every Event requires the teacher to submit an Estimate
 * form to the Version's Registration Manager, but the form itself isn't
 * built yet. A real route/page (not just an inert button) so a Founder can
 * register it in Trackable Pages (App\Support\FastPass) ahead of the real
 * implementation — the route takes a single {version} parameter, matching
 * TrackablePages::isTrackable()'s requirement.
 */
#[Layout('components.layouts.app')]
class EstimateForm extends Component
{
    use GuardsAcceptedObligations;

    public Version $version;

    public function mount(Version $version): void
    {
        $teacher = $this->teacher();

        $invitation = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->first();

        abort_if($invitation === null, 403);

        if ($this->redirectUnlessObligationsAccepted($version, $invitation)) {
            return;
        }

        $this->version = $version;
    }

    public function render(): View
    {
        return view('livewire.registrations.estimate-form');
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
