<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\GuardsAcceptedObligations;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionPitchFile;
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
        $allPitchFiles = $this->version->pitchFiles()->with('voicePart')->get();

        return view('livewire.registrations.pitch-files', [
            'pitchFiles' => $this->filter($allPitchFiles),
            'availableVoiceParts' => $this->version->availableVoiceParts(),
            'nameOptions' => $allPitchFiles->pluck('name')->unique()->sort()->values(),
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
