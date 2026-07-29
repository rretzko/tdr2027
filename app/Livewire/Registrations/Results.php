<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Registered candidates for a single closed Version, with a switcher
 * (populated the same way as ResultsIndex) so a teacher can jump between
 * their closed Versions without going back through the dashboard.
 */
#[Layout('components.layouts.app')]
class Results extends Component
{
    public Version $version;

    public string $switchVersionId = '';

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
        $this->switchVersionId = (string) $version->id;
    }

    public function updatedSwitchVersionId(): void
    {
        if ($this->switchVersionId === (string) $this->version->id) {
            return;
        }

        $this->redirectRoute('registrations.results', ['version' => $this->switchVersionId], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.registrations.results', [
            'candidates' => $this->candidates(),
            'switcherOptions' => $this->switcherOptions(),
        ]);
    }

    /**
     * @return Collection<int, Candidate>
     */
    private function candidates(): Collection
    {
        $teacher = $this->teacher();

        return Candidate::where('version_id', $this->version->id)
            ->where('teacher_id', $teacher->id)
            ->where('status', CandidateStatus::Registered)
            ->with(['student.user', 'voicePart'])
            ->get()
            ->sortBy(fn (Candidate $candidate): string => mb_strtolower($candidate->student->user->sort_name))
            ->values();
    }

    /**
     * Other closed Versions this teacher has standing in, for the
     * page-top switcher — same qualifying query as ResultsIndex::buildItems(),
     * so the switcher's options always match what's listed on the dashboard.
     *
     * @return Collection<int, Version>
     */
    private function switcherOptions(): Collection
    {
        $teacher = $this->teacher();

        $versionIds = Candidate::where('teacher_id', $teacher->id)->pluck('version_id')->unique();

        return Version::with('event')
            ->whereIn('id', $versionIds)
            ->where('status', 'closed')
            ->get()
            ->sort(function (Version $a, Version $b): int {
                $seniorClassOf = $b->senior_class_of <=> $a->senior_class_of;

                return $seniorClassOf !== 0 ? $seniorClassOf : $a->name <=> $b->name;
            })
            ->values();
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
