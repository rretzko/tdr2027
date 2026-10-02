<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\ReadinessPhase;
use App\Enums\ReadinessStatus;
use App\Models\Version;
use App\Services\Readiness\ReadinessResult;
use App\Services\Readiness\VersionReadiness;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Version setup punchlist — every configuration decision, grouped by
 * when it's needed, with computed status. See docs/plans/version-readiness.md.
 */
#[Layout('components.layouts.app')]
class VersionReadinessChecklist extends Component
{
    public Version $version;

    #[Url(as: 'hidden')]
    public bool $showNotApplicable = false;

    /**
     * The item whose setting this viewer can't change, shown in the
     * "who can change this" modal. Guarded-action pattern: Open / Looks
     * right stay visible for every viewer and explain on click, rather than
     * being hidden or leading to a 403.
     */
    public ?string $lockedKey = null;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageReadiness(Auth::user(), $version), 403);

        $this->version = $version;
    }

    public function acknowledge(string $key, VersionReadiness $readiness, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageReadiness(Auth::user(), $this->version), 403);

        $result = $readiness->evaluate($this->version->fresh())->get($key);
        abort_if($result === null, 404);

        if (! $readiness->editorAccess(Auth::user(), $this->version)[$result->item->editor->value]) {
            $this->explainLocked($key);

            return;
        }

        if (! $result->canAcknowledge) {
            Flux::toast(text: 'That item needs to be set up before it can be confirmed.', variant: 'warning');

            return;
        }

        $readiness->acknowledge($this->version, $key, Auth::user());

        Flux::toast(text: 'Marked as reviewed: '.$result->item->question, variant: 'success');
    }

    public function explainLocked(string $key): void
    {
        $this->lockedKey = $key;
        $this->modal('readiness-locked')->show();
    }

    /**
     * Marks the first-visit spotlight tour as seen — called by the tour
     * engine's hidden trigger on finish/skip, same as Events\Show. The
     * "Take a tour" button ignores this flag.
     */
    public function dismissOrientation(): void
    {
        Auth::user()->update(['dismissed_readiness_orientation_at' => now()]);
    }

    public function render(VersionReadiness $readiness, VersionRoleAssignmentService $roles): View
    {
        $results = $readiness->evaluate($this->version->fresh());
        $hiddenCount = $results->filter(fn (ReadinessResult $r): bool => $r->status === ReadinessStatus::NotApplicable)->count();

        $visible = $this->showNotApplicable
            ? $results
            : $results->reject(fn (ReadinessResult $r): bool => $r->status === ReadinessStatus::NotApplicable);

        $phases = collect(ReadinessPhase::cases())
            ->map(fn (ReadinessPhase $phase): array => [
                'phase' => $phase,
                'results' => $visible->filter(fn (ReadinessResult $r): bool => $r->item->phase === $phase)->values(),
            ])
            ->filter(fn (array $group): bool => $group['results']->isNotEmpty());

        return view('livewire.events.version-readiness-checklist', [
            'summary' => $readiness->summary($results),
            'phases' => $phases,
            'dueDates' => $readiness->dueDates($this->version),
            'hiddenCount' => $hiddenCount,
            'canConfigure' => $roles->canManageEvent(Auth::user(), $this->version->event),
            'editable' => $readiness->editorAccess(Auth::user(), $this->version),
            'lockedItem' => $this->lockedKey !== null ? $readiness->item($this->lockedKey) : null,
            'eventManagers' => $this->lockedKey !== null ? $roles->eventManagersForEvent($this->version->event) : collect(),
            // Tour anchors: the first visible row, and the first row offering
            // "Looks right" (null when nothing is reviewable — the tour then
            // explains the button against the Status column instead).
            'tourFirstKey' => $visible->keys()->first(),
            'tourAckKey' => $visible->first(fn (ReadinessResult $r): bool => $r->canAcknowledge)?->item->key,
        ]);
    }
}
