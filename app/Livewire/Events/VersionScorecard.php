<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\VersionDateType;
use App\Models\Version;
use App\Models\VersionDate;
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
        $deadline = $this->postmarkDeadline();

        $rows = $snapshots->map(fn (VersionScorecardSnapshot $snapshot): array => [
            'date' => Carbon::parse($snapshot->getRawOriginal('captured_on'))->toDateString(),
            ...$this->chartLabels(Carbon::parse($snapshot->getRawOriginal('captured_on')), $deadline),
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
            'deadline' => $deadline,
        ]);
    }

    /**
     * The Postmark Deadline as a display-timezone calendar day, or null when
     * the Version hasn't configured one.
     */
    private function postmarkDeadline(): ?Carbon
    {
        $startAt = VersionDate::where('version_id', $this->version->id)
            ->where('date_type', VersionDateType::PostmarkDeadline)
            ->value('start_at');

        // Re-parsed from its display-timezone date string so it's midnight in
        // the app timezone, same as captured_on — whole-day diffs between them.
        return $startAt === null ? null : Carbon::parse(Carbon::parse($startAt)->forDisplay()->toDateString());
    }

    /**
     * Pre-formatted strings, since Flux draws non-date x values as categorical
     * labels verbatim. With a deadline, the x label counts weeks down to it:
     * 0 is the snapshot whose Monday falls in the deadline's week (deadline
     * within the next 6 days), −1 the Monday before, +1 the Monday after.
     * U+2212 rather than a hyphen, which Flux's date sniffing would treat as a
     * date. Without a deadline it falls back to the snapshot's month and day.
     *
     * @return array{x: string, heading: string}
     */
    private function chartLabels(Carbon $capturedOn, ?Carbon $deadline): array
    {
        $date = $capturedOn->format('M j, Y');

        if ($deadline === null) {
            return ['x' => $capturedOn->format('M j'), 'heading' => $date];
        }

        $week = (int) ceil($deadline->diffInDays($capturedOn) / 7);
        $label = match (true) {
            $week < 0 => "\u{2212}".abs($week),
            $week > 0 => '+'.$week,
            default => '0',
        };

        return ['x' => $label, 'heading' => "Week {$label} · {$date}"];
    }
}
