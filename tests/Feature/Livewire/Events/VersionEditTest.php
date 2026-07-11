<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Enums\VersionApplicationStatus;
use App\Enums\VersionDateType;
use App\Enums\VersionObligationStatus;
use App\Livewire\Events\VersionEdit;
use App\Models\County;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionDate;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionFee;
use App\Models\VersionMembershipRequirement;
use App\Models\VersionObligation;
use App\Models\VersionUploadFile;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function makeVersionEditUser(): User
{
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);

    return $user;
}

test('mount populates the general tab fields from the Version', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['name' => 'Spring 2027 Chorus', 'judge_count' => 3]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->assertSet('name', 'Spring 2027 Chorus')
        ->assertSet('judge_count', '3');
});

test('saveGeneral updates the Version record', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['name' => 'Old Name']);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('name', 'New Name')
        ->set('status', EventStatus::Active->value)
        ->set('application_type', ApplicationType::Pdf->value)
        ->set('audition_type', AuditionType::Remote->value)
        ->set('upload_type', UploadType::None->value)
        ->set('score_order', ScoreOrder::Asc->value)
        ->set('pitch_file_visibility', PitchFileVisibility::Both->value)
        ->call('saveGeneral')
        ->assertHasNoErrors();

    expect($version->fresh()->name)->toBe('New Name');
    expect($version->fresh()->status)->toBe(EventStatus::Active);
});

test('Audition Slot shows for in_person and hides for remote, toggling live', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['audition_type' => AuditionType::InPerson->value]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->assertSee('Audition Slot (minutes)')
        ->set('audition_type', AuditionType::Remote->value)
        ->assertDontSee('Audition Slot (minutes)')
        ->set('audition_type', AuditionType::InPerson->value)
        ->assertSee('Audition Slot (minutes)');
});

test('saveGeneral requires at least 5 minutes for in_person but allows 0 for remote', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('status', EventStatus::Active->value)
        ->set('application_type', ApplicationType::Pdf->value)
        ->set('upload_type', UploadType::None->value)
        ->set('score_order', ScoreOrder::Asc->value)
        ->set('pitch_file_visibility', PitchFileVisibility::Both->value)
        ->set('audition_type', AuditionType::Remote->value)
        ->set('audition_timeslot', '0')
        ->call('saveGeneral')
        ->assertHasNoErrors();

    expect($version->fresh()->audition_timeslot)->toBe(0);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('status', EventStatus::Active->value)
        ->set('application_type', ApplicationType::Pdf->value)
        ->set('upload_type', UploadType::None->value)
        ->set('score_order', ScoreOrder::Asc->value)
        ->set('pitch_file_visibility', PitchFileVisibility::Both->value)
        ->set('audition_type', AuditionType::InPerson->value)
        ->set('audition_timeslot', '0')
        ->call('saveGeneral')
        ->assertHasErrors('audition_timeslot');
});

test('saveGeneral accepts 0 for max_upper_voice_registrants', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('status', EventStatus::Active->value)
        ->set('application_type', ApplicationType::Pdf->value)
        ->set('upload_type', UploadType::None->value)
        ->set('score_order', ScoreOrder::Asc->value)
        ->set('pitch_file_visibility', PitchFileVisibility::Both->value)
        ->set('audition_type', AuditionType::Remote->value)
        ->set('max_upper_voice_registrants', '0')
        ->call('saveGeneral')
        ->assertHasNoErrors();

    expect($version->fresh()->max_upper_voice_registrants)->toBe(0);
});

test('saveGeneral requires a name', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('name', '')
        ->call('saveGeneral')
        ->assertHasErrors('name');
});

test('saveDates creates rows for each populated VersionDateType', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('date_start.'.VersionDateType::Teacher->value, '2027-01-01T09:00')
        ->set('date_start.'.VersionDateType::Adjudication->value, '2027-02-01T09:00')
        ->set('date_end.'.VersionDateType::Adjudication->value, '2027-02-02T17:00')
        ->call('saveDates')
        ->assertHasNoErrors();

    expect(VersionDate::where('version_id', $version->id)->count())->toBe(2);

    $adjudication = VersionDate::where('version_id', $version->id)
        ->where('date_type', VersionDateType::Adjudication->value)
        ->first();

    expect($adjudication->getRawOriginal('end_at'))->not->toBeNull();
});

test('saveDates rejects an end_at before start_at for date types with a window', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('date_start.'.VersionDateType::Adjudication->value, '2027-02-02T09:00')
        ->set('date_end.'.VersionDateType::Adjudication->value, '2027-02-01T09:00')
        ->call('saveDates')
        ->assertHasErrors('date_end.'.VersionDateType::Adjudication->value);
});

test('saveDates removes a date row when its start is cleared', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();

    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Teacher->value,
        'start_at' => now(),
        'end_at' => null,
    ]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('date_start.'.VersionDateType::Teacher->value, '')
        ->call('saveDates')
        ->assertHasNoErrors();

    expect(VersionDate::where('version_id', $version->id)->where('date_type', VersionDateType::Teacher->value)->exists())->toBeFalse();
});

test('saveFees converts dollar input to cents on the VersionFee row', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('fee_registration', '25.00')
        ->set('fee_on_site_registration', '0')
        ->set('fee_participation', '75.50')
        ->set('fee_epayment_surcharge', '2.50')
        ->set('fee_housing', '0')
        ->call('saveFees')
        ->assertHasNoErrors();

    $fee = VersionFee::where('version_id', $version->id)->first();

    expect($fee->registration)->toBe(2500);
    expect($fee->participation)->toBe(7550);
    expect($fee->epayment_surcharge)->toBe(250);
});

test('saveRequirements saves membership requirement and syncs selected counties', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('membership_card', true)
        ->set('membership_valid_thru', '2027-08-31')
        ->set('selected_county_ids', [$countyA->id, $countyB->id])
        ->call('saveRequirements')
        ->assertHasNoErrors();

    $requirement = VersionMembershipRequirement::where('version_id', $version->id)->first();
    expect($requirement->membership_card)->toBeTrue();

    expect($version->fresh()->counties->pluck('county_id')->all())->toEqualCanonicalizing([$countyA->id, $countyB->id]);
});

test('saveEnsembleOrder persists the order_by value for each ensemble', function () {
    $user = makeVersionEditUser();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    $first = Ensemble::factory()->create(['event_id' => $event->id]);
    $second = Ensemble::factory()->create(['event_id' => $event->id]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set("ensemble_order.{$first->id}", 2)
        ->set("ensemble_order.{$second->id}", 1)
        ->call('saveEnsembleOrder')
        ->assertHasNoErrors();

    expect(VersionEnsembleOrder::where('version_id', $version->id)->where('ensemble_id', $first->id)->value('order_by'))->toBe(2);
    expect(VersionEnsembleOrder::where('version_id', $version->id)->where('ensemble_id', $second->id)->value('order_by'))->toBe(1);
});

test('the Expected Upload Files section shows only when Upload Type is audio or video', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['upload_type' => UploadType::None->value]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->assertDontSee('Expected Upload Files')
        ->set('upload_type', UploadType::Audio->value)
        ->assertSee('Expected Upload Files')
        ->set('upload_type', UploadType::Video->value)
        ->assertSee('Expected Upload Files')
        ->set('upload_type', UploadType::None->value)
        ->assertDontSee('Expected Upload Files');
});

test('addUploadFile creates a row with the next order_by and appends it to the array', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['upload_type' => UploadType::Audio->value]);
    VersionUploadFile::create(['version_id' => $version->id, 'name' => 'scales', 'order_by' => 1]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('new_upload_file_name', 'solo')
        ->call('addUploadFile')
        ->assertHasNoErrors()
        ->assertSet('new_upload_file_name', '');

    $solo = VersionUploadFile::where('version_id', $version->id)->where('name', 'solo')->first();

    expect($solo)->not->toBeNull();
    expect($solo->order_by)->toBe(2);
    expect($version->fresh()->upload_file_count)->toBe(2);
});

test('addUploadFile requires a name', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['upload_type' => UploadType::Audio->value]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('new_upload_file_name', '')
        ->call('addUploadFile')
        ->assertHasErrors('new_upload_file_name');
});

test('saveUploadFiles persists renamed labels and reordered values', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['upload_type' => UploadType::Audio->value]);
    $scales = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'scales', 'order_by' => 1]);
    $solo = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'solo', 'order_by' => 2]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set("upload_files.{$scales->id}.name", 'scales renamed')
        ->set("upload_files.{$scales->id}.order_by", 2)
        ->set("upload_files.{$solo->id}.order_by", 1)
        ->call('saveUploadFiles')
        ->assertHasNoErrors();

    expect($scales->fresh()->name)->toBe('scales renamed');
    expect($scales->fresh()->order_by)->toBe(2);
    expect($solo->fresh()->order_by)->toBe(1);
});

test('removeUploadFile deletes the row and decrements the derived count', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['upload_type' => UploadType::Audio->value]);
    $scales = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'scales', 'order_by' => 1]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->call('removeUploadFile', $scales->id)
        ->assertSet('upload_files', []);

    expect(VersionUploadFile::find($scales->id))->toBeNull();
    expect($version->fresh()->upload_file_count)->toBe(0);
});

test('uploadFileOrderColor reuses the darkest ramp step for any order_by beyond the ramp', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    $component = Livewire::actingAs($user)->test(VersionEdit::class, ['version' => $version]);

    /** @var VersionEdit $instance */
    $instance = $component->instance();

    expect($instance->uploadFileOrderColor(1))->toBe('#86b6ef');
    expect($instance->uploadFileOrderColor(4))->toBe('#1c5cab');
    expect($instance->uploadFileOrderColor(5))->toBe('#1c5cab');
    expect($instance->uploadFileOrderColor(99))->toBe('#1c5cab');
});

test('mount aborts with 403 for a user holding no version-scoped role on the Version', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount allows a user holding any of the 6 version-scoped roles, not just Event Manager', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Tab Room Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->assertOk();
});

test('mount allows Founder regardless of any role assignment', function () {
    $founder = makeFounder();
    $version = Version::factory()->create();

    Livewire::actingAs($founder)
        ->test(VersionEdit::class, ['version' => $version])
        ->assertOk();
});

test('the Roles tab shows current assignments and lets an Event Manager assign a new role', function () {
    $eventManager = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $newHire = User::factory()->create(['email' => 'newhire@example.com']);

    Livewire::actingAs($eventManager)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('assign_email', 'newhire@example.com')
        ->set('assign_role', 'Registration Manager')
        ->call('assignRole')
        ->assertHasNoErrors()
        ->assertSet('assign_email', '')
        ->assertSet('assign_role', '');

    expect(
        app(VersionRoleAssignmentService::class)
            ->assignmentsForVersion($version)
            ->get('Registration Manager')
            ->pluck('id'),
    )->toContain($newHire->id);
});

test('assignRole shows a field error when no user exists with that email', function () {
    $eventManager = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    Livewire::actingAs($eventManager)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('assign_email', 'nobody@example.com')
        ->set('assign_role', 'Registration Manager')
        ->call('assignRole')
        ->assertHasErrors('assign_email');
});

test('a Registration Manager can see the Roles tab but cannot assign or revoke roles', function () {
    $registrationManager = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    $someoneElse = User::factory()->create();

    Livewire::actingAs($registrationManager)
        ->test(VersionEdit::class, ['version' => $version])
        ->assertOk()
        ->assertViewHas('canManageRoles', false);

    expect(fn () => app(VersionRoleAssignmentService::class)
        ->assignRole($registrationManager, $version, $someoneElse, 'Tab Room Manager'))
        ->toThrow(HttpException::class);
});

test('revokeRole removes an assignment when called by an Event Manager', function () {
    $eventManager = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    $rehearsalManager = User::factory()->create();
    grantVersionRole($rehearsalManager, $version, 'Rehearsal Manager');

    Livewire::actingAs($eventManager)
        ->test(VersionEdit::class, ['version' => $version])
        ->call('revokeRole', $rehearsalManager->id, 'Rehearsal Manager');

    expect(
        app(VersionRoleAssignmentService::class)
            ->assignmentsForVersion($version)
            ->get('Rehearsal Manager')
            ->pluck('id'),
    )->not->toContain($rehearsalManager->id);
});

test('saveObligation creates a draft row without publishing it', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('obligation_title', 'Teacher Obligations')
        ->set('obligation_body', '<p>Be excellent.</p>')
        ->call('saveObligation')
        ->assertHasNoErrors()
        ->assertSet('obligation_status', 'draft');

    $obligation = VersionObligation::where('version_id', $version->id)->first();

    expect($obligation)->not->toBeNull();
    expect($obligation->title)->toBe('Teacher Obligations');
    expect($obligation->getRawOriginal('status'))->toBe('draft');
    expect($obligation->published_at)->toBeNull();
});

test('saveObligation strips disallowed HTML via the obligations purifier profile', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('obligation_body', '<p><strong>Bold</strong> text</p><script>alert(1)</script><img src="x.png" onerror="alert(2)">')
        ->call('saveObligation');

    $obligation = VersionObligation::where('version_id', $version->id)->first();

    expect($obligation->body)->toContain('<strong>Bold</strong>');
    expect($obligation->body)->not->toContain('<script');
    expect($obligation->body)->not->toContain('<img');
    expect($obligation->body)->not->toContain('onerror');
});

test('saveObligation on an already-published obligation does not change its status', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Original.</p>',
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('obligation_body', '<p>Edited wording.</p>')
        ->call('saveObligation')
        ->assertSet('obligation_status', 'published');

    $obligation = VersionObligation::where('version_id', $version->id)->first();

    expect($obligation->getRawOriginal('status'))->toBe('published');
    expect($obligation->body)->toContain('Edited wording.');
});

test('publishObligation saves the current text and stamps published_at/published_by_user_id', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('obligation_title', 'Teacher Obligations')
        ->set('obligation_body', '<p>Be excellent.</p>')
        ->call('publishObligation')
        ->assertSet('obligation_status', 'published');

    $obligation = VersionObligation::where('version_id', $version->id)->first();

    expect($obligation->getRawOriginal('status'))->toBe('published');
    expect($obligation->published_at)->not->toBeNull();
    expect($obligation->published_by_user_id)->toBe($user->id);
});

test('unpublishObligation reverts a published obligation back to draft', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Text.</p>',
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->call('unpublishObligation')
        ->assertSet('obligation_status', 'draft');

    $obligation = VersionObligation::where('version_id', $version->id)->first();

    expect($obligation->getRawOriginal('status'))->toBe('draft');
});

test('the Obligations preview reflects unsaved edits with merge fields resolved', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['short_name' => 'TDR27']);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('activeTab', 'obligations')
        ->set('obligation_title', 'Draft Title')
        ->set('obligation_body', '<p>Hello {{versionShortName}}.</p>')
        ->assertSee('Draft Title')
        ->assertSee('Hello TDR27');

    expect(VersionObligation::where('version_id', $version->id)->exists())->toBeFalse();
});

test('the Obligations preview shows an empty-state message when there is no text yet', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('activeTab', 'obligations')
        ->assertSee('Nothing to preview yet');
});

test('saveApplication creates a draft row without publishing it, for an EApplication-mode Version', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['application_type' => ApplicationType::EApplication->value]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('student_endorsement_body', '<p>Student text.</p>')
        ->set('parent_endorsement_body', '<p>Parent text.</p>')
        ->call('saveApplication')
        ->assertHasNoErrors()
        ->assertSet('application_status', 'draft');

    $application = VersionApplication::where('version_id', $version->id)->first();

    expect($application)->not->toBeNull();
    expect($application->teacher_principal_endorsement_body)->toBeNull();
    expect($application->getRawOriginal('status'))->toBe('draft');
});

test('saveApplication requires the Teacher/Principal body for a Pdf-mode Version', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['application_type' => ApplicationType::Pdf->value]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('student_endorsement_body', '<p>Student text.</p>')
        ->set('parent_endorsement_body', '<p>Parent text.</p>')
        ->set('teacher_principal_endorsement_body', '')
        ->call('saveApplication')
        ->assertHasErrors(['teacher_principal_endorsement_body']);

    expect(VersionApplication::where('version_id', $version->id)->exists())->toBeFalse();
});

test('saveApplication succeeds for a Pdf-mode Version when the Teacher/Principal body is present', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['application_type' => ApplicationType::Pdf->value]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('student_endorsement_body', '<p>Student text.</p>')
        ->set('parent_endorsement_body', '<p>Parent text.</p>')
        ->set('teacher_principal_endorsement_body', '<p>Teacher text.</p>')
        ->call('saveApplication')
        ->assertHasNoErrors();

    $application = VersionApplication::where('version_id', $version->id)->first();

    expect($application->teacher_principal_endorsement_body)->toContain('Teacher text.');
});

test('saveApplication strips disallowed HTML from all three bodies via the shared purifier profile', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['application_type' => ApplicationType::Pdf->value]);
    grantVersionRole($user, $version, 'Event Manager');

    $dirty = '<p><strong>Bold</strong> text</p><script>alert(1)</script><img src="x.png" onerror="alert(2)">';

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('student_endorsement_body', $dirty)
        ->set('parent_endorsement_body', $dirty)
        ->set('teacher_principal_endorsement_body', $dirty)
        ->call('saveApplication');

    $application = VersionApplication::where('version_id', $version->id)->first();

    foreach ([
        $application->student_endorsement_body,
        $application->parent_endorsement_body,
        $application->teacher_principal_endorsement_body,
    ] as $body) {
        expect($body)->toContain('<strong>Bold</strong>');
        expect($body)->not->toContain('<script');
        expect($body)->not->toContain('onerror');
    }
});

test('publishApplication fails to publish a Pdf-mode Version missing the Teacher/Principal body', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['application_type' => ApplicationType::Pdf->value]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('student_endorsement_body', '<p>Student text.</p>')
        ->set('parent_endorsement_body', '<p>Parent text.</p>')
        ->call('publishApplication')
        ->assertHasErrors(['teacher_principal_endorsement_body']);

    expect(VersionApplication::where('version_id', $version->id)->exists())->toBeFalse();
});

test('publishApplication stamps published_at/published_by_user_id', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['application_type' => ApplicationType::EApplication->value]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('student_endorsement_body', '<p>Student text.</p>')
        ->set('parent_endorsement_body', '<p>Parent text.</p>')
        ->call('publishApplication')
        ->assertSet('application_status', 'published');

    $application = VersionApplication::where('version_id', $version->id)->first();

    expect($application->getRawOriginal('status'))->toBe('published');
    expect($application->published_at)->not->toBeNull();
    expect($application->published_by_user_id)->toBe($user->id);
});

test('unpublishApplication reverts a published Application back to draft', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['application_type' => ApplicationType::EApplication->value]);
    grantVersionRole($user, $version, 'Event Manager');

    VersionApplication::create([
        'version_id' => $version->id,
        'student_endorsement_body' => '<p>Student text.</p>',
        'parent_endorsement_body' => '<p>Parent text.</p>',
        'status' => VersionApplicationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->call('unpublishApplication')
        ->assertSet('application_status', 'draft');

    $application = VersionApplication::where('version_id', $version->id)->first();

    expect($application->getRawOriginal('status'))->toBe('draft');
});

test('the Application preview resolves merge fields and shows the Teacher/Principal section only in Pdf mode', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['application_type' => ApplicationType::Pdf->value, 'short_name' => 'TDR27']);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('activeTab', 'application')
        ->set('student_endorsement_body', '<p>Hello {{candidateFullName}}, welcome to {{versionShortName}}.</p>')
        ->set('parent_endorsement_body', '<p>Parent of {{candidateFullName}}.</p>')
        ->set('teacher_principal_endorsement_body', '<p>Teacher section text.</p>')
        ->assertSee('Hello Jane A. Sample')
        ->assertSee('welcome to TDR27')
        ->assertSee('Teacher section text.');
});

test('the Application preview omits the Teacher/Principal section for an EApplication-mode Version', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create(['application_type' => ApplicationType::EApplication->value]);
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('activeTab', 'application')
        ->set('student_endorsement_body', '<p>Student text.</p>')
        ->set('parent_endorsement_body', '<p>Parent text.</p>')
        ->assertDontSee('Teacher/Principal Endorsement');
});

test('the Application preview shows an empty-state message when there is no text yet', function () {
    $user = makeVersionEditUser();
    $version = Version::factory()->create();
    grantVersionRole($user, $version, 'Event Manager');

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('activeTab', 'application')
        ->assertSee('Nothing to preview yet');
});
