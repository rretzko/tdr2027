<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Models\Version;
use App\Models\VersionPitchFile;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class VersionPitchFiles extends Component
{
    use WithFileUploads;

    public Version $version;

    #[Url]
    public string $search = '';

    #[Url]
    public string $voicePartFilter = '';

    #[Url]
    public string $nameFilter = '';

    public string $sortColumn = 'order_by';

    public string $sortDirection = 'asc';

    public ?int $editingId = null;

    public string $name = '';

    public string $voice_part_id = '';

    public string $description = '';

    public $newFile = null;

    /** @var array<int, int> */
    public array $orderInputs = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $version->event), 403);

        $this->version = $version;
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function add(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->voice_part_id = '';
        $this->description = '';
        $this->newFile = null;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $pitchFile = $this->version->pitchFiles()->findOrFail($id);

        $this->editingId = $pitchFile->id;
        $this->name = $pitchFile->name;
        $this->voice_part_id = (string) $pitchFile->voice_part_id;
        $this->description = $pitchFile->description ?? '';
        $this->newFile = null;
        $this->resetErrorBag();
    }

    public function save(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);

        $validVoicePartIds = $this->version->availableVoiceParts()->pluck('id')->all();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'voice_part_id' => ['required', 'integer', Rule::in($validVoicePartIds)],
            'description' => ['nullable', 'string', 'max:255'],
            'newFile' => [$this->editingId === null ? 'required' : 'nullable', 'file', 'mimes:mp3,wav,m4a,pdf,mp4,mov', 'max:51200'],
        ]);

        $data = [
            'name' => $validated['name'],
            'voice_part_id' => (int) $validated['voice_part_id'],
            'description' => $validated['description'] !== null && $validated['description'] !== '' ? $validated['description'] : null,
        ];

        if ($this->newFile !== null) {
            $data['url'] = $this->newFile->store("pitchFiles/{$this->version->id}", 's3');
        }

        if ($this->editingId === null) {
            $data['order_by'] = ((int) $this->version->pitchFiles()->max('order_by')) + 1;
            $pitchFile = $this->version->pitchFiles()->create($data);
        } else {
            $pitchFile = VersionPitchFile::where('id', $this->editingId)->where('version_id', $this->version->id)->firstOrFail();
            $previousUrl = $pitchFile->url;
            $pitchFile->update($data);

            if ($this->newFile !== null && $previousUrl !== $pitchFile->url) {
                Storage::disk('s3')->delete($previousUrl);
            }
        }

        $this->editingId = null;
        $this->newFile = null;
        $this->modal('pitch-file-form')->close();

        Flux::toast(text: "\"{$pitchFile->name}\" saved.", variant: 'success');
    }

    public function remove(int $id, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);

        $pitchFile = VersionPitchFile::where('id', $id)->where('version_id', $this->version->id)->firstOrFail();

        Storage::disk('s3')->delete($pitchFile->url);

        $name = $pitchFile->name;
        $pitchFile->delete();
        unset($this->orderInputs[$id]);

        Flux::toast(text: "\"{$name}\" removed.", variant: 'success');
    }

    public function saveOrder(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);

        $this->validate([
            'orderInputs' => ['array'],
            'orderInputs.*' => ['required', 'integer', 'min:1', 'max:32767'],
        ]);

        foreach ($this->orderInputs as $id => $orderBy) {
            VersionPitchFile::where('id', $id)->where('version_id', $this->version->id)->update(['order_by' => (int) $orderBy]);
        }

        Flux::toast('Pitch file order saved.');
    }

    /**
     * Drag-and-drop handler, wired to `wire:sort` in the desktop table view.
     * $item is the dragged pitch file's id (from wire:sort:item), $position
     * its new zero-based index within the visible (sorted/filtered) list.
     */
    public function reorderPitchFiles(int $item, int $position, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);

        $ids = $this->version->pitchFiles()->pluck('id')->all();
        $ids = array_values(array_filter($ids, fn (int $id): bool => $id !== $item));
        array_splice($ids, $position, 0, [$item]);

        foreach ($ids as $index => $id) {
            VersionPitchFile::where('id', $id)->where('version_id', $this->version->id)->update(['order_by' => $index + 1]);
            $this->orderInputs[$id] = $index + 1;
        }
    }

    public function render(): View
    {
        $allPitchFiles = $this->version->pitchFiles()->with('voicePart')->get();

        foreach ($allPitchFiles as $pitchFile) {
            $this->orderInputs[$pitchFile->id] ??= $pitchFile->order_by;
        }

        return view('livewire.events.version-pitch-files', [
            'pitchFiles' => $this->filterAndSort($allPitchFiles),
            'availableVoiceParts' => $this->version->availableVoiceParts(),
            'nameOptions' => $allPitchFiles->pluck('name')->unique()->sort()->values(),
        ]);
    }

    /**
     * @param  Collection<int, VersionPitchFile>  $pitchFiles
     * @return Collection<int, VersionPitchFile>
     */
    private function filterAndSort(Collection $pitchFiles): Collection
    {
        $search = mb_strtolower(trim($this->search));

        if ($search !== '') {
            $pitchFiles = $pitchFiles->filter(fn (VersionPitchFile $pitchFile): bool => str_contains(mb_strtolower($pitchFile->name), $search)
                || str_contains(mb_strtolower((string) $pitchFile->description), $search));
        }

        if ($this->voicePartFilter !== '') {
            $pitchFiles = $pitchFiles->filter(fn (VersionPitchFile $pitchFile): bool => (string) $pitchFile->voice_part_id === $this->voicePartFilter);
        }

        if ($this->nameFilter !== '') {
            $pitchFiles = $pitchFiles->filter(fn (VersionPitchFile $pitchFile): bool => $pitchFile->name === $this->nameFilter);
        }

        $sortValue = fn (VersionPitchFile $pitchFile): string|int => match ($this->sortColumn) {
            'voice_part' => mb_strtolower($pitchFile->voicePart->name),
            'description' => mb_strtolower((string) $pitchFile->description),
            'order_by' => $pitchFile->order_by,
            default => mb_strtolower($pitchFile->name),
        };

        $pitchFiles = $this->sortDirection === 'desc'
            ? $pitchFiles->sortByDesc($sortValue)
            : $pitchFiles->sortBy($sortValue);

        return $pitchFiles->values();
    }
}
