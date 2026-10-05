<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Models\Version;
use App\Models\VersionScorecardSnapshot;
use App\Services\VersionRoleAssignmentService;
use App\Services\VersionScorecardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The Monday Morning Scorecard as a page: this moment's numbers (computed
 * live, same as the email) above weekly trend charts drawn from
 * VersionScorecardSnapshot rows, which only exist from the first Monday the
 * snapshot job ran — there's no backfill.
 */
#[Layout('components.layouts.app')]
class VersionScorecard extends Component
{
    public Version $version;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $version->event), 403);

        $this->version = $version;
    }

    public function render(VersionScorecardService $scorecard): View
    {
        $snapshots = $this->version->scorecardSnapshots()->orderBy('captured_on')->get();

        $rows = $snapshots->map(fn (VersionScorecardSnapshot $snapshot): array => [
            'date' => Carbon::parse($snapshot->getRawOriginal('captured_on'))->toDateString(),
            'invited_teachers' => $snapshot->invited_teachers,
            'obligated_teachers' => $snapshot->obligated_teachers,
            'registered_teachers' => $snapshot->registered_teachers,
            'invited_schools' => $snapshot->invited_schools,
            'obligated_schools' => $snapshot->obligated_schools,
            'registered_schools' => $snapshot->registered_schools,
            'eligible_students' => $snapshot->eligible_students,
            'engaged_students' => $snapshot->engaged_students,
            'registered_students' => $snapshot->registered_students,
            'fees_due' => $snapshot->registration_fees_due_cents / 100,
            'fees_paid' => $snapshot->registration_fees_paid_cents / 100,
            'fees_outstanding' => $snapshot->registration_fees_outstanding_cents / 100,
        ])->values()->all();

        return view('livewire.events.version-scorecard', [
            'metrics' => $scorecard->metricsFor($this->version),
            'rows' => $rows,
        ]);
    }
}
