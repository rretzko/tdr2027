<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\DirectorRowOutcome;
use App\Models\Version;
use App\Services\DirectorUploadService;
use App\Services\Readiness\VersionReadiness;
use App\Services\VersionRoleAssignmentService;
use App\Support\DirectorUpload\DirectorCsvParser;
use App\Support\DirectorUpload\DirectorUploadRow;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Event Manager director CSV upload, hosted in a modal on the Invitations
 * page (docs/plans/director-csv-upload.md). Two steps: upload → preview
 * (read-only, flags possible duplicates for resolution), then confirm →
 * commit. Nothing is written before confirm.
 */
class DirectorUpload extends Component
{
    use WithFileUploads;

    public Version $version;

    public ?TemporaryUploadedFile $csvFile = null;

    /** 'upload' | 'preview' | 'done' */
    public string $step = 'upload';

    /** @var list<array<string, mixed>> parsed DirectorUploadRow::toArray() data, carried to commit */
    public array $rows = [];

    /** @var list<array<string, mixed>> DirectorRowPreview::toArray() data, for display */
    public array $preview = [];

    /** @var list<string> */
    public array $notices = [];

    /** @var array<int|string, array{school?: string, person?: string}> keyed by CSV line */
    public array $resolutions = [];

    /** @var array<string, mixed>|null DirectorUploadResult::toArray() */
    public ?array $result = null;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $version->event), 403);

        $this->version = $version;
    }

    public function previewUpload(DirectorCsvParser $parser, DirectorUploadService $service, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);

        $this->validate([
            'csvFile' => ['required', 'file', 'extensions:csv,txt', 'max:1024'],
        ], [
            'csvFile.required' => 'Choose a CSV file to upload.',
            'csvFile.extensions' => 'The file must be a .csv file.',
        ]);

        $parsed = $parser->parse((string) $this->csvFile?->get());

        if ($parsed->fileErrors !== []) {
            $this->addError('csvFile', implode(' ', $parsed->fileErrors));

            return;
        }

        $this->rows = array_map(fn (DirectorUploadRow $row): array => $row->toArray(), $parsed->rows);
        $this->notices = $parsed->notices;
        $this->preview = [];
        $this->resolutions = [];

        foreach ($service->preview($this->version, $parsed->rows) as $rowPreview) {
            $data = $rowPreview->toArray();
            $this->preview[] = $data;

            if ($data['schoolChoices'] !== [] || $data['personChoices'] !== []) {
                $this->resolutions[$data['line']] = array_filter([
                    'school' => $data['schoolChoices'] !== [] ? '' : null,
                    'person' => $data['personChoices'] !== [] ? '' : null,
                ], fn (?string $v): bool => $v !== null);
            }
        }

        $this->step = 'preview';
    }

    public function confirmUpload(DirectorUploadService $service, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);

        if ($this->unresolvedCount() > 0) {
            $this->addError('resolutions', 'Choose an option for every row marked "Needs review" before confirming.');

            return;
        }

        $result = $service->commit(
            $this->version,
            array_map(fn (array $row): DirectorUploadRow => DirectorUploadRow::fromArray($row), $this->rows),
            $this->resolutions,
            Auth::user(),
        );

        $this->result = $result->toArray();
        $this->step = 'done';

        if ($result->changedAnything()) {
            app(VersionReadiness::class)->markSectionReviewed($this->version, 'invitations', Auth::user());
            $this->dispatch('directors-uploaded');
        }

        $message = "{$result->invited} director(s) invited to {$this->version->name}";
        $details = array_filter([
            $result->newUsers > 0 ? "{$result->newUsers} new account(s)" : null,
            $result->newSchools > 0 ? "{$result->newSchools} new school(s)" : null,
            $result->alreadyInvited > 0 ? "{$result->alreadyInvited} already invited" : null,
            $result->skipped > 0 ? "{$result->skipped} skipped" : null,
        ]);

        Flux::toast(
            text: $message.($details !== [] ? ' — '.implode(', ', $details) : '').'.',
            variant: $result->skipped > 0 ? 'warning' : 'success',
        );
    }

    public function startOver(): void
    {
        $this->reset(['csvFile', 'step', 'rows', 'preview', 'notices', 'resolutions', 'result']);
        $this->resetValidation();
    }

    private function unresolvedCount(): int
    {
        $count = 0;

        foreach ($this->resolutions as $resolution) {
            foreach ($resolution as $choice) {
                if ($choice === '') {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function render(): View
    {
        $counts = array_fill_keys(array_map(fn (DirectorRowOutcome $o): string => $o->value, DirectorRowOutcome::cases()), 0);

        foreach ($this->preview as $row) {
            $counts[$row['outcome']]++;
        }

        return view('livewire.events.director-upload', [
            'outcomeCounts' => $counts,
            'unresolvedCount' => $this->unresolvedCount(),
            'requiredHeaders' => DirectorCsvParser::REQUIRED_HEADERS,
            'optionalHeaders' => DirectorCsvParser::OPTIONAL_HEADERS,
        ]);
    }
}
