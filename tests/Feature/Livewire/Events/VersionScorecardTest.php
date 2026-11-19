<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Events;

use App\Enums\EventStatus;
use App\Enums\JudgeType;
use App\Enums\VersionDateType;
use App\Livewire\Events\VersionScorecard;
use App\Mail\MondayMorningScorecardMail;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RoomJudge;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionRoom;
use App\Models\VersionScorecardSnapshot;
use App\Models\VoicePart;
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

    public function test_lands_on_the_registration_tab_before_adjudication_opens(): void
    {
        $version = $this->makeVersion();

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->assertSet('tab', 'registration')
            ->assertSee('Invited Teachers')
            ->assertDontSee('Overall progress');
    }

    public function test_lands_on_the_adjudication_tab_once_the_window_opens(): void
    {
        $version = $this->makeVersion();
        VersionDate::create([
            'version_id' => $version->id,
            'date_type' => VersionDateType::Adjudication,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDays(5),
        ]);
        VersionRoom::create(['version_id' => $version->id, 'name' => 'Treble Scales', 'tolerance' => null, 'order_by' => 1]);

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->assertSet('tab', 'adjudication')
            ->assertSee('Adjudication is open')
            ->assertSee('Overall progress')
            ->assertSee('Treble Scales');
    }

    public function test_switching_to_the_adjudication_tab_renders_progress(): void
    {
        $version = $this->makeVersion();

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->set('tab', 'adjudication')
            ->assertSee('No adjudication window is set')
            ->assertSee('No Rooms are set up for this Version yet');
    }

    public function test_the_adjudication_tab_lists_judges_with_their_own_students(): void
    {
        $version = $this->makeVersion();
        $room = VersionRoom::create(['version_id' => $version->id, 'name' => 'Treble Solos', 'tolerance' => null, 'order_by' => 1]);
        $voicePart = VoicePart::factory()->create();
        $room->voiceParts()->attach($voicePart->id);
        $judgeUser = User::factory()->create(['name' => 'Pat Judge', 'email' => 'pat.judge@example.com']);
        RoomJudge::factory()->create(['version_id' => $version->id, 'room_id' => $room->id, 'user_id' => $judgeUser->id, 'judge_type' => JudgeType::Judge1]);
        $teacher = Teacher::factory()->create(['user_id' => $judgeUser->id]);
        Candidate::factory()->registered()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'teacher_id' => $teacher->id]);

        Livewire::actingAs($this->makeEventManager($version))
            ->test(VersionScorecard::class, ['version' => $version])
            ->set('tab', 'adjudication')
            ->assertSee('Judges scoring their own students')
            ->assertSee('1 own student')
            ->assertSee('no co-judge scores to compare yet')
            // Not started, so also listed under the nudge section; the email is a mailto link.
            ->assertSeeHtml('href="mailto:pat.judge@example.com"');
    }

    public function test_the_monday_email_links_to_the_scorecard_page(): void
    {
        $version = $this->makeVersion();

        $mail = new MondayMorningScorecardMail($version, app(VersionScorecardService::class)->metricsFor($version), User::factory()->create());

        $mail->assertSeeInHtml(route('events.versions.scorecard', $version));
    }
}
