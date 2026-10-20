<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Events;

use App\Enums\EventStatus;
use App\Enums\VersionDateType;
use App\Livewire\Events\VersionScorecard;
use App\Mail\MondayMorningScorecardMail;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionScorecardSnapshot;
use App\Services\VersionRoleService;
use App\Services\VersionScorecardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VersionScorecardTest extends TestCase
{
    use RefreshDatabase;

    private function makeVersion(): Version
    {
        $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        return Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Active]);
    }

    private function makeEventManager(Version $version): User
    {
        $eventManager = User::factory()->create();
        app(VersionRoleService::class)->withVersion($version, fn () => $eventManager->assignRole('Event Manager'));

        return $eventManager;
    }

    /**
     * @param  array<string, int>  $overrides
     */
    private function makeSnapshot(Version $version, string $capturedOn, array $overrides = []): VersionScorecardSnapshot
    {
        return VersionScorecardSnapshot::create(array_merge([
            'version_id' => $version->id,
            'captured_on' => $capturedOn,
            'invited_teachers' => 0,
            'invited_schools' => 0,
            'eligible_students' => 0,
            'obligated_teachers' => 0,
            'obligated_schools' => 0,
            'engaged_students' => 0,
            'registered_teachers' => 0,
            'registered_schools' => 0,
            'registered_students' => 0,
            'registration_fees_due_cents' => 0,
            'registration_fees_paid_cents' => 0,
            'registration_fees_outstanding_cents' => 0,
        ], $overrides));
    }

    public function test_is_forbidden_to_a_user_with_no_role_on_the_event(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(VersionScorecard::class, ['version' => $this->makeVersion()])
            ->assertStatus(403);
    }

    public function test_an_event_manager_sees_the_current_numbers(): void
    {
        $version = $this->makeVersion();

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->assertOk()
            ->assertSee('Invited Teachers')
            ->assertSee('Registration Fees');
    }

    public function test_shows_the_not_yet_message_before_any_snapshot(): void
    {
        $version = $this->makeVersion();

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->assertSee("The first one hasn't been taken yet.", escape: false)
            ->assertDontSee('Snapshot history');
    }

    public function test_one_snapshot_explains_that_charts_need_a_second_monday(): void
    {
        $version = $this->makeVersion();
        $this->makeSnapshot($version, '2026-10-05');

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->assertSee('The first snapshot was taken Monday, October 5.')
            ->assertSee('Snapshot history');
    }

    public function test_two_snapshots_render_the_trend_charts_and_history(): void
    {
        $version = $this->makeVersion();
        $this->makeSnapshot($version, '2026-10-05', ['registered_students' => 12, 'registration_fees_paid_cents' => 15000]);
        $this->makeSnapshot($version, '2026-10-12', ['registered_students' => 40, 'registration_fees_paid_cents' => 52500]);

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->assertSee('Since October 5, 2026.')
            ->assertDontSee('Trends need at least two Mondays')
            ->assertSee('Oct 12, 2026')
            ->assertSee('$525.00');
    }

    public function test_chart_x_axis_counts_weeks_down_to_the_postmark_deadline(): void
    {
        $version = $this->makeVersion();
        // 11:59pm Eastern on Friday, Nov 20 — already Nov 21 in UTC.
        VersionDate::create([
            'version_id' => $version->id,
            'date_type' => VersionDateType::PostmarkDeadline,
            'start_at' => '2026-11-21 04:59:00',
            'end_at' => '2026-11-21 04:59:00',
        ]);
        foreach (['2026-10-19', '2026-11-09', '2026-11-16', '2026-11-23'] as $capturedOn) {
            $this->makeSnapshot($version, $capturedOn);
        }

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->assertSee('Weeks count down to the Postmark Deadline (Friday, November 20)')
            ->assertViewHas('rows', fn (array $rows): bool => array_column($rows, 'x') === ["\u{2212}4", "\u{2212}1", '0', '+1']
                && $rows[0]['heading'] === "Week \u{2212}4 · Oct 19, 2026");
    }

    public function test_chart_x_axis_falls_back_to_dates_without_a_postmark_deadline(): void
    {
        $version = $this->makeVersion();
        $this->makeSnapshot($version, '2026-10-05');
        $this->makeSnapshot($version, '2026-10-12');

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->assertDontSee('Weeks count down')
            ->assertViewHas('rows', fn (array $rows): bool => array_column($rows, 'x') === ['Oct 5', 'Oct 12']
                && $rows[1]['heading'] === 'Oct 12, 2026');
    }

    public function test_only_shows_this_versions_snapshots(): void
    {
        $version = $this->makeVersion();
        $this->makeSnapshot($this->makeVersion(), '2026-09-28');

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->assertDontSee('Sep 28, 2026')
            ->assertDontSee('Snapshot history');
    }

    public function test_the_monday_email_links_to_the_scorecard_page(): void
    {
        $version = $this->makeVersion();

        $mail = new MondayMorningScorecardMail($version, app(VersionScorecardService::class)->metricsFor($version));

        $mail->assertSeeInHtml(route('events.versions.scorecard', $version));
    }
}
