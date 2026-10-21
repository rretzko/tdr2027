<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\EventStatus;
use App\Enums\UnsubscribableEmail;
use App\Enums\VersionDateType;
use App\Mail\MondayMorningScorecardMail;
use App\Models\EmailUnsubscribe;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionScorecardSnapshot;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendMondayMorningScorecardEmailsTest extends TestCase
{
    use RefreshDatabase;

    private function makeVersion(EventStatus $status): Version
    {
        $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        return Version::factory()->create(['event_id' => $event->id, 'status' => $status]);
    }

    private function makeEventManager(Version $version): User
    {
        $eventManager = User::factory()->create();
        app(VersionRoleService::class)->withVersion($version, fn () => $eventManager->assignRole('Event Manager'));

        return $eventManager;
    }

    public function test_emails_the_event_manager_of_an_active_version(): void
    {
        Mail::fake();

        $version = $this->makeVersion(EventStatus::Active);
        $eventManager = $this->makeEventManager($version);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        Mail::assertSent(MondayMorningScorecardMail::class, fn ($mail) => $mail->hasTo($eventManager->email) && $mail->version->is($version));
    }

    public function test_does_not_email_for_a_non_active_version(): void
    {
        Mail::fake();

        $version = $this->makeVersion(EventStatus::Sandbox);
        $eventManager = $this->makeEventManager($version);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        Mail::assertNotSent(MondayMorningScorecardMail::class, fn ($mail) => $mail->hasTo($eventManager->email));
    }

    public function test_does_not_email_a_manager_assigned_to_a_sibling_version_of_the_same_event(): void
    {
        Mail::fake();

        $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $activeVersion = Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Active]);
        $sandboxVersion = Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Sandbox]);

        $siblingManager = $this->makeEventManager($sandboxVersion);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        Mail::assertNotSent(MondayMorningScorecardMail::class, fn ($mail) => $mail->hasTo($siblingManager->email) && $mail->version->is($activeVersion));
    }

    public function test_does_not_error_when_an_active_version_has_no_event_manager(): void
    {
        Mail::fake();

        $this->makeVersion(EventStatus::Active);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_records_a_snapshot_for_each_active_version(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-05 07:30', 'America/New_York'));

        $active = $this->makeVersion(EventStatus::Active);
        $this->makeEventManager($active);
        $sandbox = $this->makeVersion(EventStatus::Sandbox);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        $snapshot = VersionScorecardSnapshot::where('version_id', $active->id)->sole();
        $this->assertSame('2026-10-05', Carbon::parse($snapshot->getRawOriginal('captured_on'))->toDateString());
        $this->assertDatabaseMissing('version_scorecard_snapshots', ['version_id' => $sandbox->id]);
    }

    public function test_records_a_snapshot_even_when_an_active_version_has_no_event_manager(): void
    {
        Mail::fake();

        $version = $this->makeVersion(EventStatus::Active);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        $this->assertDatabaseHas('version_scorecard_snapshots', ['version_id' => $version->id]);
    }

    public function test_rerunning_on_the_same_day_does_not_duplicate_the_snapshot(): void
    {
        Mail::fake();

        $version = $this->makeVersion(EventStatus::Active);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();
        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        $this->assertSame(1, VersionScorecardSnapshot::where('version_id', $version->id)->count());
    }

    public function test_stops_emailing_and_snapshotting_once_adjudication_has_started(): void
    {
        Mail::fake();

        $version = $this->makeVersion(EventStatus::Active);
        $eventManager = $this->makeEventManager($version);
        VersionDate::create(['version_id' => $version->id, 'date_type' => VersionDateType::Adjudication, 'start_at' => now()->subHour(), 'end_at' => now()->addDay()]);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        Mail::assertNotSent(MondayMorningScorecardMail::class, fn ($mail) => $mail->hasTo($eventManager->email));
        $this->assertSame(0, VersionScorecardSnapshot::where('version_id', $version->id)->count());
    }

    public function test_keeps_emailing_while_adjudication_is_still_in_the_future(): void
    {
        Mail::fake();

        $version = $this->makeVersion(EventStatus::Active);
        $eventManager = $this->makeEventManager($version);
        VersionDate::create(['version_id' => $version->id, 'date_type' => VersionDateType::Adjudication, 'start_at' => now()->addHour(), 'end_at' => now()->addDay()]);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        Mail::assertSent(MondayMorningScorecardMail::class, fn ($mail) => $mail->hasTo($eventManager->email));
    }

    public function test_skips_an_event_manager_who_unsubscribed_but_still_records_the_snapshot(): void
    {
        Mail::fake();

        $version = $this->makeVersion(EventStatus::Active);
        $unsubscribed = $this->makeEventManager($version);
        $subscribed = $this->makeEventManager($version);
        EmailUnsubscribe::create(['user_id' => $unsubscribed->id, 'email_type' => UnsubscribableEmail::MondayScorecard]);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        Mail::assertNotSent(MondayMorningScorecardMail::class, fn ($mail) => $mail->hasTo($unsubscribed->email));
        Mail::assertSent(MondayMorningScorecardMail::class, fn ($mail) => $mail->hasTo($subscribed->email) && $mail->recipient->is($subscribed));
        $this->assertSame(1, VersionScorecardSnapshot::where('version_id', $version->id)->count());
    }

    public function test_unsubscribing_from_the_director_update_does_not_stop_the_scorecard(): void
    {
        Mail::fake();

        $version = $this->makeVersion(EventStatus::Active);
        $eventManager = $this->makeEventManager($version);
        EmailUnsubscribe::create(['user_id' => $eventManager->id, 'email_type' => UnsubscribableEmail::DirectorWeeklyUpdate]);

        $this->artisan('versions:send-monday-morning-scorecard')->assertSuccessful();

        Mail::assertSent(MondayMorningScorecardMail::class, fn ($mail) => $mail->hasTo($eventManager->email));
    }
}
