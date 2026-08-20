<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\GuardsAcceptedObligations;
use App\Enums\PitchFileVisibility;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionPitchFile;
use App\Services\CoTeacherAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Teacher-facing, read-only view of a Version's pitch files — the
 * candidate/teacher-facing display §9 item 11 flagged as deferred when
 * Version Pitch Files (§5.5) was first built for Event Manager CRUD only.
 * Filterable by Voice Part and Name; a pitch file whose Voice Part is the
 * seeded 'ALL' voice part (VoicePartSeeder) always displays regardless of
 * the Voice Part filter, since it's meant to apply across every part.
 *
 * Gated on `versions.pitch_file_visibility` since studentfolder-module.md
 * §5.5's second clarifying pass (2026-08-18) — `Teacher`/`Both` shows every
 * pitch file to the teacher as before; `Candidate`-only now shows none,
 * a real behavior change to this already-shipped page (it previously
 * ignored the setting entirely). The student-facing equivalent
 * (`App\Livewire\Sfdi\Events\PitchFiles`) enforces the mirror-image rule.
 */
#[Layout('components.layouts.app')]
class PitchFiles extends Component
{
    use GuardsAcceptedObligations;

    public Version $version;

    #[Url]
    public string $voicePartFilter = '';

    #[Url]
    public string $nameFilter = '';

    public function mount(Version $version, CoTeacherAccessService $coTeacherAccess): void
    {
        $teacher = $this->teacher();

        $invitation = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->first();

        if ($invitation === null) {
            // Reachable from VersionDashboard's quick-links row, which a
            // granted co-teacher with no invitation of their own can now
            // open too (docs/plans/co-teacher-definition.md §3) — let them
            // through here as well rather than dead-ending on this link.
            // Reference material, not the candidate's own obligations
            // standing, so no redirect check applies on this path.
            $hasGrantedCandidates = $coTeacherAccess->candidateQuery($teacher)
                ->where('version_id', $version->id)
                ->exists();

            abort_unless($hasGrantedCandidates, 403);

            $this->version = $version;

            return;
        }

        if ($this->redirectUnlessObligationsAccepted($version, $invitation)) {
            return;
        }

        $this->version = $version;
    }

    public function render(): View
    {
        $visibleToTeacher = in_array(
            $this->version->getRawOriginal('pitch_file_visibility'),
            [PitchFileVisibility::Both->value, PitchFileVisibility::Teacher->value],
            true,
        );

        $allPitchFiles = $visibleToTeacher
            ? $this->version->pitchFiles()->with('voicePart')->get()
            : new Collection;

        return view('livewire.registrations.pitch-files', [
            'pitchFiles' => $this->filter($allPitchFiles),
            'availableVoiceParts' => $this->version->availableVoiceParts(),
            'nameOptions' => $allPitchFiles->pluck('name')->unique()->sort()->values(),
            'visibleToTeacher' => $visibleToTeacher,
        ]);
    }

    /**
     * @param  Collection<int, VersionPitchFile>  $pitchFiles
     * @return Collection<int, VersionPitchFile>
     */
    private function filter(Collection $pitchFiles): Collection
    {
        if ($this->voicePartFilter !== '') {
            $pitchFiles = $pitchFiles->filter(
                fn (VersionPitchFile $pitchFile): bool => (string) $pitchFile->voice_part_id === $this->voicePartFilter
                    || $pitchFile->voicePart->abbr === 'ALL',
            );
        }

        if ($this->nameFilter !== '') {
            $pitchFiles = $pitchFiles->filter(fn (VersionPitchFile $pitchFile): bool => $pitchFile->name === $this->nameFilter);
        }

        return $pitchFiles
            ->sortBy(fn (VersionPitchFile $pitchFile): array => [$pitchFile->voicePart->sort_order, $pitchFile->order_by])
            ->values();
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
