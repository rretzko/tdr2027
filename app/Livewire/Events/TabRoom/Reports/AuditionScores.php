<?php

declare(strict_types=1);

namespace App\Livewire\Events\TabRoom\Reports;

use App\Models\Version;
use App\Models\VoicePart;
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tab Room Module.docx's "Audition Scores by voice part" report: one Voice
 * Part's full per-judge, per-factor score table, best-to-worst. PDF only,
 * per the source doc (unlike the other four reports, which also offer CSV).
 */
#[Layout('components.layouts.app')]
class AuditionScores extends Component
{
    public Version $version;

    #[Url]
    public ?int $voicePartId = null;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageTabRoomReports(Auth::user(), $version), 403);

        $this->version = $version;

        // The <select> visually defaults to its first <option> (Flux's
        // placeholder isn't a real selectable option) — default the actual
        // filter state to match, rather than leaving the table empty under
        // a dropdown that already reads as "Soprano I". #[Url] hydrates
        // voicePartId before mount() runs, so an explicit query-string
        // selection is never overwritten here.
        $this->voicePartId ??= $version->availableVoiceParts()->first()?->id;
    }

    public function render(TabRoomReportService $reports, EnsembleCutoffService $cutoffs): View
    {
        $voicePart = $this->voicePartId !== null ? VoicePart::find($this->voicePartId) : null;
        $data = $voicePart !== null ? $reports->auditionScoreRows($this->version, $voicePart, $cutoffs) : null;

        return view('livewire.events.tab-room.reports.audition-scores', [
            'voiceParts' => $this->version->availableVoiceParts(),
            'columns' => $data['columns'] ?? collect(),
            'categoryGroups' => $data['categoryGroups'] ?? collect(),
            'judgeGroups' => $data['judgeGroups'] ?? collect(),
            'rows' => $data['rows'] ?? collect(),
        ]);
    }
}
