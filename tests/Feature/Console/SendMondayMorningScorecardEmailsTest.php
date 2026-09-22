<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\EventStatus;
use App\Mail\MondayMorningScorecardMail;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
