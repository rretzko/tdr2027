<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\EventStatus;
use App\Enums\VersionDateType;
use App\Mail\TeacherAccessWindowExpiringMail;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotifyExpiringTeacherAccessWindowsTest extends TestCase
{
    use RefreshDatabase;

    private function makeVersion(bool $active = false): Version
    {
        $event = Event::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $version = Version::factory()->create(['event_id' => $event->id]);

        if ($active) {
            $version->update(['status' => EventStatus::Active]);
        }

        return $version;
    }

    private function makeEventManager(Version $version): User
    {
        $eventManager = User::factory()->create();
        app(VersionRoleService::class)->withVersion($version, fn () => $eventManager->assignRole('Event Manager'));

        return $eventManager;
    }

    private function makeTeacherDate(Version $version, \DateTimeInterface $startAt, ?\DateTimeInterface $endAt = null): VersionDate
    {
        return VersionDate::create([
            'version_id' => $version->id,
            'date_type' => VersionDateType::Teacher,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ]);
    }

    public function test_notifies_the_event_manager_when_a_non_active_version_teacher_window_opens_within_5_days(): void
    {
        Mail::fake();

        $version = $this->makeVersion();
        $eventManager = $this->makeEventManager($version);
        $this->makeTeacherDate($version, now()->addDays(3));

        $this->artisan('versions:notify-expiring-teacher-access')->assertSuccessful();

        Mail::assertSent(TeacherAccessWindowExpiringMail::class, fn ($mail) => $mail->hasTo($eventManager->email) && $mail->version->is($version));
    }

    public function test_does_not_notify_when_the_version_is_already_active(): void
    {
        Mail::fake();

        $version = $this->makeVersion(active: true);
        $eventManager = $this->makeEventManager($version);
        $this->makeTeacherDate($version, now()->addDays(3));

        $this->artisan('versions:notify-expiring-teacher-access')->assertSuccessful();

        Mail::assertNotSent(TeacherAccessWindowExpiringMail::class, fn ($mail) => $mail->hasTo($eventManager->email) && $mail->version->is($version));
    }

    public function test_does_not_notify_when_the_window_is_more_than_5_days_out(): void
    {
        Mail::fake();

        $version = $this->makeVersion();
        $eventManager = $this->makeEventManager($version);
        $this->makeTeacherDate($version, now()->addDays(10));

        $this->artisan('versions:notify-expiring-teacher-access')->assertSuccessful();

        Mail::assertNotSent(TeacherAccessWindowExpiringMail::class, fn ($mail) => $mail->hasTo($eventManager->email) && $mail->version->is($version));
    }

    public function test_notifies_when_the_window_already_opened_within_the_last_5_days_and_has_no_end_at(): void
    {
        Mail::fake();

        $version = $this->makeVersion();
        $eventManager = $this->makeEventManager($version);
        $this->makeTeacherDate($version, now()->subDays(2));

        $this->artisan('versions:notify-expiring-teacher-access')->assertSuccessful();

        Mail::assertSent(TeacherAccessWindowExpiringMail::class, fn ($mail) => $mail->hasTo($eventManager->email));
    }

    public function test_does_not_notify_once_the_window_has_been_open_more_than_5_days(): void
    {
        Mail::fake();

        $version = $this->makeVersion();
        $eventManager = $this->makeEventManager($version);
        $this->makeTeacherDate($version, now()->subDays(6));

        $this->artisan('versions:notify-expiring-teacher-access')->assertSuccessful();

        Mail::assertNotSent(TeacherAccessWindowExpiringMail::class, fn ($mail) => $mail->hasTo($eventManager->email) && $mail->version->is($version));
    }

    public function test_does_not_notify_when_the_window_already_ended(): void
    {
        Mail::fake();

        $version = $this->makeVersion();
        $eventManager = $this->makeEventManager($version);
        $this->makeTeacherDate($version, now()->subDays(10), now()->subDay());

        $this->artisan('versions:notify-expiring-teacher-access')->assertSuccessful();

        Mail::assertNotSent(TeacherAccessWindowExpiringMail::class, fn ($mail) => $mail->hasTo($eventManager->email) && $mail->version->is($version));
    }

    public function test_does_not_notify_for_a_non_teacher_date_type(): void
    {
        Mail::fake();

        $version = $this->makeVersion();
        $eventManager = $this->makeEventManager($version);

        VersionDate::create([
            'version_id' => $version->id,
            'date_type' => VersionDateType::Candidate,
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(30),
        ]);

        $this->artisan('versions:notify-expiring-teacher-access')->assertSuccessful();

        Mail::assertNotSent(TeacherAccessWindowExpiringMail::class, fn ($mail) => $mail->hasTo($eventManager->email) && $mail->version->is($version));
    }
}
