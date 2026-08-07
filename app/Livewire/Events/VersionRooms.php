<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\CandidateStatus;
use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use App\Models\Candidate;
use App\Models\RoomJudge;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionRoom;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class VersionRooms extends Component
{
    public Version $version;

    public ?int $editingId = null;

    public string $name = '';

    public string $tolerance = '';

    /** @var list<int> */
    public array $scoreCategoryIds = [];

    /** @var list<int> */
    public array $voicePartIds = [];

    /** @var array<int, int> */
    public array $orderInputs = [];

    /** @var list<array{id: int|null, user_id: int|null, search: string, judge_type: string}> */
    public array $judges = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function add(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->tolerance = '';
        $this->scoreCategoryIds = [];
        $this->voicePartIds = [];
        $this->judges = [];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $room = $this->version->rooms()->findOrFail($id);

        $this->editingId = $room->id;
        $this->name = $room->name;
        $this->tolerance = $room->tolerance === null ? '' : (string) $room->tolerance;
        $this->scoreCategoryIds = $room->scoreCategories()->pluck('score_categories.id')->map(fn ($id): int => (int) $id)->all();
        $this->voicePartIds = $room->voiceParts()->pluck('voice_parts.id')->map(fn ($id): int => (int) $id)->all();
        $this->judges = $room->roomJudges()->with('user')->get()
            ->map(fn (RoomJudge $roomJudge): array => [
                'id' => $roomJudge->id,
                'user_id' => $roomJudge->user_id,
                'search' => $roomJudge->user->name ?? '',
                'judge_type' => (string) $roomJudge->getRawOriginal('judge_type'),
            ])->all();
        $this->resetErrorBag();
    }

    public function addJudgeRow(): void
    {
        $this->judges[] = ['id' => null, 'user_id' => null, 'search' => '', 'judge_type' => ''];
    }

    public function removeJudgeRow(int $index): void
    {
        unset($this->judges[$index]);
        $this->judges = array_values($this->judges);
    }

    /**
     * @return Collection<int, User>
     */
    public function judgeSearchResults(int $index): Collection
    {
        $search = trim($this->judges[$index]['search'] ?? '');

        if ($search === '' || ($this->judges[$index]['user_id'] ?? null) !== null) {
            return new Collection;
        }

        return User::query()
            ->whereDoesntHave('student')
            ->where('name', 'like', "%{$search}%")
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    public function selectJudgeUser(int $index, int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->judges[$index]['user_id'] = $user->id;
        $this->judges[$index]['search'] = $user->name;
    }

    public function clearJudgeSelection(int $index): void
    {
        $this->judges[$index]['user_id'] = null;
        $this->judges[$index]['search'] = '';
    }

    /**
     * A selected judge's Room assignment history across every Version of the
     * current Event, most recent Version first — surfaced so Event Managers
     * can avoid repeatedly stacking a judge into a high-volume Room across
     * sequential Versions.
     *
     * @return Collection<int, RoomJudge>
     */
    public function judgeAssignmentHistory(int $index): Collection
    {
        $userId = $this->judges[$index]['user_id'] ?? null;

        if ($userId === null) {
            return new Collection;
        }

        return RoomJudge::query()
            ->where('user_id', $userId)
            ->whereHas('version', fn ($query) => $query->where('event_id', $this->version->event_id))
            ->with(['room', 'version'])
            ->get()
            ->sortByDesc(fn (RoomJudge $roomJudge): int => $roomJudge->version->senior_class_of)
            ->values();
    }

    public function save(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);

        $validCategoryIds = $this->version->availableScoreCategories()->pluck('id')->all();
        $validVoicePartIds = $this->version->availableVoiceParts()->pluck('id')->all();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'tolerance' => ['nullable', 'integer', 'min:0', 'max:255'],
            'scoreCategoryIds' => ['array'],
            'scoreCategoryIds.*' => ['integer', Rule::in($validCategoryIds)],
            'voicePartIds' => ['array'],
            'voicePartIds.*' => ['integer', Rule::in($validVoicePartIds)],
            'judges' => ['array'],
            'judges.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'judges.*.judge_type' => ['required', Rule::in(array_map(fn (JudgeType $case): string => $case->value, JudgeType::cases()))],
        ]);

        $data = [
            'name' => $validated['name'],
            'tolerance' => $validated['tolerance'] !== null && $validated['tolerance'] !== '' ? (int) $validated['tolerance'] : null,
        ];

        if ($this->editingId === null) {
            $data['order_by'] = ((int) $this->version->rooms()->max('order_by')) + 1;
            $room = $this->version->rooms()->create($data);
        } else {
            $room = VersionRoom::where('id', $this->editingId)->where('version_id', $this->version->id)->firstOrFail();
            $room->update($data);
        }

        $room->scoreCategories()->sync($validated['scoreCategoryIds']);
        $room->voiceParts()->sync($validated['voicePartIds']);

        $keepIds = [];

        foreach ($validated['judges'] as $index => $row) {
            $existingId = $this->judges[$index]['id'] ?? null;
            $roomJudge = $existingId !== null
                ? RoomJudge::where('id', $existingId)->where('room_id', $room->id)->first()
                : null;

            if ($roomJudge !== null) {
                $roomJudge->update(['user_id' => $row['user_id'], 'judge_type' => $row['judge_type']]);
            } else {
                $roomJudge = $room->roomJudges()->create([
                    'version_id' => $this->version->id,
                    'user_id' => $row['user_id'],
                    'judge_type' => $row['judge_type'],
                    'status' => JudgeStatus::Assigned,
                ]);
            }

            $keepIds[] = $roomJudge->id;
        }

        RoomJudge::where('room_id', $room->id)->whereNotIn('id', $keepIds)->delete();

        $this->editingId = null;
        $this->modal('room-form')->close();

        Flux::toast(text: "\"{$room->name}\" saved.", variant: 'success');
    }

    public function remove(int $id, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);

        $room = VersionRoom::where('id', $id)->where('version_id', $this->version->id)->firstOrFail();

        $name = $room->name;
        $room->delete();
        unset($this->orderInputs[$id]);

        Flux::toast(text: "\"{$name}\" removed.", variant: 'success');
    }

    public function saveOrder(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);

        $this->validate([
            'orderInputs' => ['array'],
            'orderInputs.*' => ['required', 'integer', 'min:1', 'max:255'],
        ]);

        foreach ($this->orderInputs as $id => $orderBy) {
            VersionRoom::where('id', $id)->where('version_id', $this->version->id)->update(['order_by' => (int) $orderBy]);
        }

        Flux::toast('Room order saved.');
    }

    /**
     * Drag-and-drop handler, native HTML5 (wire:sort is broken on Flux
     * table rows in this codebase — see [[project_livewire_wire_sort]]).
     */
    public function reorderRooms(int $item, int $position, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $this->version), 403);

        $ids = $this->version->rooms()->pluck('id')->all();
        $ids = array_values(array_filter($ids, fn (int $id): bool => $id !== $item));
        array_splice($ids, $position, 0, [$item]);

        foreach ($ids as $index => $id) {
            VersionRoom::where('id', $id)->where('version_id', $this->version->id)->update(['order_by' => $index + 1]);
            $this->orderInputs[$id] = $index + 1;
        }
    }

    public function render(): View
    {
        $rooms = $this->version->rooms()->with(['scoreCategories', 'voiceParts', 'roomJudges.user'])->get();

        foreach ($rooms as $room) {
            $this->orderInputs[$room->id] ??= $room->order_by;
        }

        $candidatesByVoicePart = Candidate::query()
            ->where('version_id', $this->version->id)
            ->where('status', CandidateStatus::Registered->value)
            ->selectRaw('voice_part_id, count(*) as total')
            ->groupBy('voice_part_id')
            ->pluck('total', 'voice_part_id');

        $roomCandidateCounts = $rooms->mapWithKeys(fn (VersionRoom $room): array => [
            $room->id => $room->voiceParts->sum(fn ($voicePart): int => (int) $candidatesByVoicePart->get($voicePart->id, 0)),
        ]);

        return view('livewire.events.version-rooms', [
            'rooms' => $rooms,
            'availableScoreCategories' => $this->version->availableScoreCategories(),
            'availableVoiceParts' => $this->version->availableVoiceParts(),
            'judgeTypeOptions' => JudgeType::cases(),
            'roomCandidateCounts' => $roomCandidateCounts,
        ]);
    }
}
