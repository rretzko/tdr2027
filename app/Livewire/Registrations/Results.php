<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Models\Candidate;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Placeholder landing page for closed Versions, linked from ResultsIndex —
 * results reporting isn't built yet.
 */
#[Layout('components.layouts.app')]
class Results extends Component
{
    public Version $version;

    public function mount(Version $version): void
    {
        $teacher = $this->teacher();

        // ResultsIndex lists a Version here whenever the teacher has a
        // Candidate in it (any status) — not every such Version necessarily
        // has a surviving VersionInvitation row, so either standing admits.
        $hasStanding = VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists()
            || Candidate::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists();

        abort_unless($hasStanding, 403);

        $this->version = $version;
    }

    public function render(): View
    {
        return view('livewire.registrations.results');
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
