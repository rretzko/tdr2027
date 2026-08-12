<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\CandidateStatus;
use App\Enums\EmergencyContactRelationship;
use App\Enums\ObligationDecision;
use App\Enums\VersionApplicationStatus;
use App\Enums\VersionObligationStatus;
use App\Livewire\Registrations\CandidateDetail;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\Ensemble;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionObligationResponse;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeCandidateDetailTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

function publishCandidateApplicationFor(Version $version): VersionApplication
{
    return VersionApplication::create([
        'version_id' => $version->id,
        'student_endorsement_body' => '<p>Student text.</p>',
        'parent_endorsement_body' => '<p>Parent text.</p>',
        'teacher_principal_endorsement_body' => $version->getRawOriginal('application_type') === ApplicationType::Pdf->value ? '<p>Teacher text.</p>' : null,
        'status' => VersionApplicationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);
}

test('mount aborts with 403 when the candidate does not belong to the teacher', function () {
    $teacher = makeCandidateDetailTeacher();
    $otherTeacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($otherTeacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $otherTeacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertStatus(403);
});

test('mount aborts with 404 when the candidate belongs to a different version', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();
    $otherVersion = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $otherVersion->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertStatus(404);
});

test('mount redirects to the obligations form when the teacher rejected the version obligation', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    $invitation = VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => 'rejected',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    $obligation = VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Be excellent.</p>',
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);

    VersionObligationResponse::create([
        'version_invitation_id' => $invitation->id,
        'version_obligation_id' => $obligation->id,
        'decision' => ObligationDecision::Rejected->value,
        'decided_at' => now(),
        'obligation_snapshot' => $obligation->body,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertRedirect(route('registrations.obligations', $version));
});

test('saveProgramName updates the candidate program name and recalculates status', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['emergency_contact_name' => false]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
        'program_name' => '',
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('program_name', 'Jane Smith')
        ->call('saveProgramName')
        ->assertHasNoErrors();

    expect($candidate->refresh()->program_name)->toBe('Jane Smith');
    expect($candidate->status)->toBe(CandidateStatus::Registered);
});

test('saveProgramName requires a non-empty value', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('program_name', '')
        ->call('saveProgramName')
        ->assertHasErrors('program_name');
});

test('saveEmergencyContact creates a contact and links it as the candidate default when none exists yet', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'emergency_contact_id' => null,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('ec_name', 'Pat Guardian')
        ->set('ec_relationship', EmergencyContactRelationship::Mother->value)
        ->set('ec_cell_phone', '5551234567')
        ->set('ec_email', 'pat@example.com')
        ->call('saveEmergencyContact')
        ->assertHasNoErrors();

    $contact = EmergencyContact::where('student_id', $candidate->student_id)->first();

    expect($contact->name)->toBe('Pat Guardian');
    expect($candidate->refresh()->emergency_contact_id)->toBe($contact->id);
});

test('saveEmergencyContact requires a name and relationship', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('ec_name', '')
        ->set('ec_relationship', '')
        ->call('saveEmergencyContact')
        ->assertHasErrors(['ec_name', 'ec_relationship']);
});

test('saveEmergencyContact succeeds with a blank email when the Version does not require it', function () {
    $teacher = makeCandidateDetailTeacher();
    // emergency_contact_cell defaults true, emergency_contact_email defaults false.
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('ec_name', 'Pat Guardian')
        ->set('ec_relationship', EmergencyContactRelationship::Mother->value)
        ->set('ec_cell_phone', '5551234567')
        ->set('ec_email', '')
        ->call('saveEmergencyContact')
        ->assertHasNoErrors();

    $contact = EmergencyContact::where('student_id', $candidate->student_id)->first();
    expect($contact->email)->toBeNull();
});

test('saveEmergencyContact requires cell phone when the Version requires it', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['emergency_contact_cell' => true]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('ec_name', 'Pat Guardian')
        ->set('ec_relationship', EmergencyContactRelationship::Mother->value)
        ->set('ec_cell_phone', '')
        ->call('saveEmergencyContact')
        ->assertHasErrors('ec_cell_phone');
});

test('saveEmergencyContact does not require cell phone when the Version does not require it', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['emergency_contact_cell' => false]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('ec_name', 'Pat Guardian')
        ->set('ec_relationship', EmergencyContactRelationship::Mother->value)
        ->set('ec_cell_phone', '')
        ->call('saveEmergencyContact')
        ->assertHasNoErrors();

    $contact = EmergencyContact::where('student_id', $candidate->student_id)->first();
    expect($contact->cell_phone)->toBeNull();
});

test('saveEmergencyContact requires email when the Version requires it', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['emergency_contact_email' => true]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('ec_name', 'Pat Guardian')
        ->set('ec_relationship', EmergencyContactRelationship::Mother->value)
        ->set('ec_cell_phone', '5551234567')
        ->set('ec_email', '')
        ->call('saveEmergencyContact')
        ->assertHasErrors('ec_email');
});

test('the Emergency contact checklist item stays incomplete until a qualifying contact has every required sub-field', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['emergency_contact_email' => true, 'emergency_contact_cell' => true]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
    ]);

    // Cell present, email missing — Version requires both, so the
    // checklist item (and therefore Registered status) stays out of reach
    // via refreshStatus(), even though "a contact exists."
    EmergencyContact::create([
        'student_id' => $candidate->student_id,
        'name' => 'Pat Guardian',
        'relationship' => EmergencyContactRelationship::Mother->value,
        'cell_phone' => '5551234567',
        'email' => null,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('refreshStatus');

    expect($candidate->refresh()->status)->not->toBe(CandidateStatus::Registered);

    EmergencyContact::where('student_id', $candidate->student_id)->update(['email' => 'pat@example.com']);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate->fresh()])
        ->call('refreshStatus');

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Registered);
});

test('editEmergencyContact populates the form from the existing contact', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $ec = EmergencyContact::create([
        'student_id' => $candidate->student_id,
        'name' => 'Pat Guardian',
        'relationship' => EmergencyContactRelationship::Mother->value,
        'cell_phone' => '5551234567',
        'email' => 'pat@example.com',
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('editEmergencyContact', $ec->id)
        ->assertSet('editingEmergencyContactId', $ec->id)
        ->assertSet('ec_name', 'Pat Guardian')
        ->assertSet('ec_relationship', EmergencyContactRelationship::Mother->value)
        ->assertSet('ec_cell_phone', '5551234567')
        ->assertSet('ec_email', 'pat@example.com');
});

test('editEmergencyContact 404s for a contact belonging to a different student', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $otherEc = EmergencyContact::factory()->create();

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('editEmergencyContact', $otherEc->id)
        ->assertStatus(404);
});

test('saveEmergencyContact updates the existing contact in place instead of creating a new one', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $ec = EmergencyContact::create([
        'student_id' => $candidate->student_id,
        'name' => 'Pat Guardian',
        'relationship' => EmergencyContactRelationship::Mother->value,
        'cell_phone' => '5551234567',
        'email' => 'pat@example.com',
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('editEmergencyContact', $ec->id)
        ->set('ec_name', 'Pat Guardian-Smith')
        ->set('ec_cell_phone', '5559876543')
        ->call('saveEmergencyContact')
        ->assertHasNoErrors();

    expect(EmergencyContact::where('student_id', $candidate->student_id)->count())->toBe(1);
    $ec->refresh();
    expect($ec->name)->toBe('Pat Guardian-Smith');
    expect($ec->cell_phone)->toBe('5559876543');
});

test('editStudent populates the form from the current student record', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $candidate->student->update(['birthday' => '2011-06-03', 'height' => 62]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('editStudent')
        ->assertSet('edit_birthday', fn (string $value): bool => \Carbon\Carbon::parse($value)->format('Y-m-d') === '2011-06-03')
        ->assertSet('edit_height', '62');
});

test('saveStudent updates name, voice part, birthday, and height, and recalculates status', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['emergency_contact_name' => false, 'birthday' => true, 'height' => true]);

    // Attaches a VoicePart to the Version's Event via an Ensemble, so it's a
    // legal option per Version::availableVoiceParts() — the pool
    // saveStudent()'s edit_voice_part_id validation is scoped to.
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $tenor = VoicePart::factory()->create(['name' => 'Tenor']);
    $ensemble->voiceParts()->attach($tenor->id);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('edit_first_name', 'Jamie')
        ->set('edit_last_name', 'Lee')
        ->set('edit_voice_part_id', (string) $tenor->id)
        ->set('edit_birthday', '2011-06-03')
        ->set('edit_height', '62')
        ->call('saveStudent')
        ->assertHasNoErrors();

    $candidate->refresh();
    $student = $candidate->student->fresh();
    expect($student->user->fresh()->first_name)->toBe('Jamie');
    expect($student->user->fresh()->last_name)->toBe('Lee');
    expect($candidate->voice_part_id)->toBe($tenor->id);
    expect(\Carbon\Carbon::parse($student->getRawOriginal('birthday'))->format('Y-m-d'))->toBe('2011-06-03');
    expect($student->height)->toBe(62);
    expect($candidate->status)->toBe(CandidateStatus::Registered);
});

test('saveStudent rejects a birthday outside the allowed age range', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('editStudent')
        ->set('edit_birthday', now()->format('Y-m-d'))
        ->call('saveStudent')
        ->assertHasErrors('edit_birthday');
});

test('saveStudent rejects a voice part not offered by this Version', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();
    $otherVoicePart = VoicePart::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('editStudent')
        ->set('edit_voice_part_id', (string) $otherVoicePart->id)
        ->call('saveStudent')
        ->assertHasErrors('edit_voice_part_id');
});

test('editHomeAddress populates the form from the existing address, if any', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['home_address' => true]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $candidate->student->homeAddress()->create([
        'address1' => '123 Main St',
        'city' => 'Springfield',
        'geo_state' => 'IL',
        'zip_code' => '62704',
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('editHomeAddress')
        ->assertSet('edit_home_address1', '123 Main St')
        ->assertSet('edit_home_city', 'Springfield')
        ->assertSet('edit_home_geo_state', 'IL')
        ->assertSet('edit_home_zip_code', '62704');
});

test('saveHomeAddress creates the address and recalculates status', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['emergency_contact_name' => false, 'home_address' => true]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('edit_home_address1', '123 Main St')
        ->set('edit_home_city', 'Springfield')
        ->set('edit_home_geo_state', 'IL')
        ->set('edit_home_zip_code', '62704')
        ->call('saveHomeAddress')
        ->assertHasNoErrors();

    $homeAddress = $candidate->refresh()->student->homeAddress->fresh();
    expect($homeAddress->address1)->toBe('123 Main St');
    expect($homeAddress->city)->toBe('Springfield');
    expect($candidate->status)->toBe(CandidateStatus::Registered);
});

test('saveHomeAddress requires address1, city, state, and zip', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['home_address' => true]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('saveHomeAddress')
        ->assertHasErrors(['edit_home_address1', 'edit_home_city', 'edit_home_geo_state', 'edit_home_zip_code']);
});

test('the Home Address section only appears when the Version requires it', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['home_address' => false]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertDontSee('Home Address');
});

test('sections render in order: Student, Home Address, Emergency Contacts, Program Name', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['home_address' => true]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertSeeInOrder(['Student', 'Home Address', 'Emergency Contacts', 'Program Name']);
});

test('refreshStatus recalculates the candidate status', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['emergency_contact_name' => false]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
        'program_name' => 'Already Set',
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('refreshStatus');

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Registered);
});

test('the certification checklist item only appears once the Candidate Application is Published', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['application_type' => ApplicationType::Pdf->value, 'emergency_contact_name' => false]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertDontSee('Signatures certified');

    publishCandidateApplicationFor($version);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version->fresh(), 'candidate' => $candidate])
        ->assertSee('Signatures certified');
});

test('toggleApplicationCertified sets and clears the certification columns and recalculates status, Pdf mode', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['application_type' => ApplicationType::Pdf->value, 'emergency_contact_name' => false]);
    publishCandidateApplicationFor($version);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('toggleApplicationCertified');

    $candidate->refresh();
    expect($candidate->application_certified_at)->not->toBeNull();
    expect($candidate->application_certified_by_user_id)->toBe($teacher->user->id);
    expect($candidate->status)->toBe(CandidateStatus::Registered);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('toggleApplicationCertified');

    $candidate->refresh();
    expect($candidate->application_certified_at)->toBeNull();
    expect($candidate->application_certified_by_user_id)->toBeNull();
});

test('toggleApplicationCertified is a no-op for an EApplication-mode Version', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['application_type' => ApplicationType::EApplication->value]);
    publishCandidateApplicationFor($version);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('toggleApplicationCertified');

    expect($candidate->refresh()->application_certified_at)->toBeNull();
});

test('toggleApplicationCandidateSigned and toggleApplicationParentSigned are independent, EApplication mode', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['application_type' => ApplicationType::EApplication->value, 'emergency_contact_name' => false]);
    publishCandidateApplicationFor($version);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('toggleApplicationCandidateSigned');

    $candidate->refresh();
    expect($candidate->application_candidate_signed_at)->not->toBeNull();
    expect($candidate->application_parent_signed_at)->toBeNull();
    expect($candidate->is_application_certified)->toBeFalse();
    expect($candidate->status)->toBe(CandidateStatus::Pending);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('toggleApplicationParentSigned');

    $candidate->refresh();
    expect($candidate->application_parent_signed_at)->not->toBeNull();
    expect($candidate->is_application_certified)->toBeTrue();
    expect($candidate->status)->toBe(CandidateStatus::Registered);
});

test('the Download PDF link is visible once Published regardless of signed state, EApplication mode', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['application_type' => ApplicationType::EApplication->value]);
    publishCandidateApplicationFor($version);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertSee('Download PDF (optional copy)');
});
