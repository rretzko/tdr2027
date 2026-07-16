<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\VersionInvitationRequestStatus;
use App\Enums\VersionInvitationStatus;
use App\Mail\VersionInvitationRequestApprovedMail;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionInvitationRequest;
use App\Services\VersionRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL as UrlFacade;
use Tests\TestCase;

class VersionInvitationRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeacherUser(): Teacher
    {
        $user = User::factory()->create();

        return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    }

    private function makeVersion(): Version
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->create(['organization_id' => $organization->id]);

        return Version::factory()->create(['event_id' => $event->id]);
    }

    private function makeEventManager(Version $version): User
    {
        $eventManager = User::factory()->create();
        app(VersionRoleService::class)->withVersion($version, fn () => $eventManager->assignRole('Event Manager'));

        return $eventManager;
    }

    private function pendingRequest(Version $version, Teacher $teacher): VersionInvitationRequest
    {
        return VersionInvitationRequest::create([
            'version_id' => $version->id,
            'teacher_id' => $teacher->id,
            'status' => VersionInvitationRequestStatus::Pending->value,
            'requested_at' => now(),
        ]);
    }

    public function test_approve_marks_the_request_approved_and_creates_a_version_invitation(): void
    {
        Mail::fake();

        $teacher = $this->makeTeacherUser();
        $version = $this->makeVersion();
        $eventManager = $this->makeEventManager($version);
        $request = $this->pendingRequest($version, $teacher);

        $approveUrl = UrlFacade::temporarySignedRoute('version-invitation-requests.approve', now()->addDays(7), [
            'versionInvitationRequest' => $request->id,
            'user' => $eventManager->id,
        ]);

        $this->get($approveUrl)->assertOk()->assertSeeText('Request approved');

        $this->assertSame(VersionInvitationRequestStatus::Approved->value, $request->fresh()->getRawOriginal('status'));
        $this->assertSame($eventManager->id, $request->fresh()->decided_by_user_id);

        $invitation = VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->first();
        $this->assertNotNull($invitation);
        $this->assertSame(VersionInvitationStatus::Invited->value, $invitation->getRawOriginal('status'));
        $this->assertSame($eventManager->id, $invitation->invited_by_user_id);

        Mail::assertSent(VersionInvitationRequestApprovedMail::class, fn ($mail) => $mail->hasTo($teacher->user->email));
    }

    public function test_approve_on_an_already_decided_request_shows_the_already_handled_page_without_side_effects(): void
    {
        Mail::fake();

        $teacher = $this->makeTeacherUser();
        $version = $this->makeVersion();
        $eventManagerA = $this->makeEventManager($version);
        $eventManagerB = $this->makeEventManager($version);
        $request = $this->pendingRequest($version, $teacher);

        $approveUrlA = UrlFacade::temporarySignedRoute('version-invitation-requests.approve', now()->addDays(7), [
            'versionInvitationRequest' => $request->id,
            'user' => $eventManagerA->id,
        ]);
        $approveUrlB = UrlFacade::temporarySignedRoute('version-invitation-requests.approve', now()->addDays(7), [
            'versionInvitationRequest' => $request->id,
            'user' => $eventManagerB->id,
        ]);

        $this->get($approveUrlA)->assertOk();
        $this->get($approveUrlB)->assertOk()->assertSeeText('Already handled');

        $this->assertSame($eventManagerA->id, $request->fresh()->decided_by_user_id);
        $this->assertSame(1, VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->count());
        Mail::assertSent(VersionInvitationRequestApprovedMail::class, 1);
    }

    public function test_deny_marks_the_request_denied_without_creating_an_invitation(): void
    {
        $teacher = $this->makeTeacherUser();
        $version = $this->makeVersion();
        $eventManager = $this->makeEventManager($version);
        $request = $this->pendingRequest($version, $teacher);

        $denyUrl = UrlFacade::temporarySignedRoute('version-invitation-requests.deny', now()->addDays(7), [
            'versionInvitationRequest' => $request->id,
            'user' => $eventManager->id,
        ]);

        $this->get($denyUrl)->assertOk()->assertSeeText('Request denied');

        $this->assertSame(VersionInvitationRequestStatus::Denied->value, $request->fresh()->getRawOriginal('status'));
        $this->assertSame($eventManager->id, $request->fresh()->decided_by_user_id);
        $this->assertFalse(VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists());
    }

    public function test_deny_page_offers_a_mailto_link_to_the_teacher(): void
    {
        $teacher = $this->makeTeacherUser();
        $version = $this->makeVersion();
        $eventManager = $this->makeEventManager($version);
        $request = $this->pendingRequest($version, $teacher);

        $denyUrl = UrlFacade::temporarySignedRoute('version-invitation-requests.deny', now()->addDays(7), [
            'versionInvitationRequest' => $request->id,
            'user' => $eventManager->id,
        ]);

        $this->get($denyUrl)->assertOk()->assertSee('mailto:'.$teacher->user->email, false);
    }

    public function test_approve_is_forbidden_when_the_signed_user_does_not_hold_event_manager_on_this_event(): void
    {
        $teacher = $this->makeTeacherUser();
        $version = $this->makeVersion();
        $notAnEventManager = User::factory()->create();
        $request = $this->pendingRequest($version, $teacher);

        $approveUrl = UrlFacade::temporarySignedRoute('version-invitation-requests.approve', now()->addDays(7), [
            'versionInvitationRequest' => $request->id,
            'user' => $notAnEventManager->id,
        ]);

        $this->get($approveUrl)->assertForbidden();
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $teacher = $this->makeTeacherUser();
        $version = $this->makeVersion();
        $eventManager = $this->makeEventManager($version);
        $request = $this->pendingRequest($version, $teacher);

        $validUrl = UrlFacade::temporarySignedRoute('version-invitation-requests.approve', now()->addDays(7), [
            'versionInvitationRequest' => $request->id,
            'user' => $eventManager->id,
        ]);

        $this->get($validUrl.'X')->assertForbidden();
    }
}
