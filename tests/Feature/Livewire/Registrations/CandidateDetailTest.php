<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\CandidateUploadStatus;
use App\Enums\EmergencyContactRelationship;
use App\Enums\ObligationDecision;
use App\Enums\UploadType;
use App\Enums\VersionApplicationStatus;
use App\Enums\VersionObligationStatus;
use App\Livewire\Registrations\CandidateDetail;
use App\Models\Candidate;
use App\Models\CandidatePayment;
use App\Models\CandidateUploadFile;
use App\Models\EmergencyContact;
use App\Models\Ensemble;
use App\Models\EpaymentCredential;
use App\Models\Recording;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionObligationResponse;
use App\Models\VersionTeacherEpaymentOptIn;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * A genuine, valid WAV file of an exact known duration (silence) — needed
 * because UploadedFile::fake()->create() produces garbage bytes with no
 * real embedded duration metadata for getID3 to extract. Built via
 * ->createWithContent() (not `new UploadedFile(...)` directly) so Livewire's
 * ->set() still recognizes it as a proper fake upload — a raw UploadedFile
 * instance trips an "Undefined property $name" error inside Livewire's own
 * file-upload handling, which only special-cases Illuminate\Http\Testing\File.
 */
function makeCandidateDetailWavFile(float $durationSeconds, string $filename = 'recording.wav'): UploadedFile
{
    $sampleRate = 8000;
    $numSamples = (int) round($durationSeconds * $sampleRate);

    $header = 'RIFF'
        .pack('V', 36 + $numSamples)
        .'WAVE'
        .'fmt '
        .pack('V', 16)
        .pack('v', 1)
        .pack('v', 1)
        .pack('V', $sampleRate)
        .pack('V', $sampleRate)
        .pack('v', 1)
        .pack('v', 8)
        .'data'
        .pack('V', $numSamples);

    return UploadedFile::fake()->createWithContent($filename, $header.str_repeat("\x80", $numSamples));
}

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
        ->assertSet('edit_birthday', fn (string $value): bool => Carbon::parse($value)->format('Y-m-d') === '2011-06-03')
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
    expect(Carbon::parse($student->getRawOriginal('birthday'))->format('Y-m-d'))->toBe('2011-06-03');
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

test('the Application card shows sign-off badges and the view modal renders the merged document body, EApplication mode', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['application_type' => ApplicationType::EApplication->value]);
    publishCandidateApplicationFor($version);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertSee('Candidate Not Signed')
        ->assertSee('Parent Not Signed')
        ->call('viewApplication')
        ->assertSee('Student text.')
        ->assertSee('Parent text.')
        ->assertDontSee('Teacher text.')
        ->assertSee('Candidate has signed')
        ->assertSee('Parent/Guardian has signed');
});

test('the Application view modal shows the Teacher/Principal section and single certify checkbox, Pdf mode', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['application_type' => ApplicationType::Pdf->value]);
    publishCandidateApplicationFor($version);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertSee('Not Certified')
        ->call('viewApplication')
        ->assertSee('Student text.')
        ->assertSee('Parent text.')
        ->assertSee('Teacher text.')
        ->assertSee('student, parent/guardian, teacher, and principal signatures');
});

test('the Application card and modal are absent when no Application is Published', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertDontSee('Application')
        ->assertDontSee('Certify');
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

test('the Recordings section is absent for an in-person audition Version', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::InPerson->value, 'upload_type' => UploadType::Audio->value]);
    VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertDontSee('Recordings');
});

test('the Recordings section is absent for a remote Version with no configured upload slots', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertDontSee('Recordings');
});

test('saveRecording uploads a new recording as pending and recalculates status', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    $file = UploadedFile::fake()->create('scales.mp3', 500, 'audio/mpeg');

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('uploadRecording', $slot->id)
        ->set('newRecordingFile', $file)
        ->call('saveRecording')
        ->assertHasNoErrors();

    $upload = CandidateUploadFile::where('candidate_id', $candidate->id)->where('version_upload_file_id', $slot->id)->first();

    expect($upload)->not->toBeNull();
    expect($upload->getRawOriginal('status'))->toBe('pending');
    Storage::disk('s3')->assertExists($upload->url);
});

test('saveRecording rejects a file type not accepted by the Version\'s upload_type', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Video->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    $file = UploadedFile::fake()->create('solo.mp3', 500, 'audio/mpeg');

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('uploadRecording', $slot->id)
        ->set('newRecordingFile', $file)
        ->call('saveRecording')
        ->assertHasErrors('newRecordingFile');
});

test('saveRecording flags an upload whose filename mentions a different slot in the same Version', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);
    $solo = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 2]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    $file = UploadedFile::fake()->create('Alto on scales.mp3', 500, 'audio/mpeg');

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('uploadRecording', $solo->id)
        ->set('newRecordingFile', $file)
        ->call('saveRecording')
        ->assertHasNoErrors();

    $upload = CandidateUploadFile::where('candidate_id', $candidate->id)->where('version_upload_file_id', $solo->id)->first();

    expect($upload->flagged_at)->not->toBeNull();
    expect($upload->flag_reason)->toContain('Scales');
    expect($upload->original_filename)->toBe('Alto on scales.mp3');
});

test('saveRecording does not flag an upload whose filename matches its own slot', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $solo = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    $file = UploadedFile::fake()->create('My Solo Recording.mp3', 500, 'audio/mpeg');

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('uploadRecording', $solo->id)
        ->set('newRecordingFile', $file)
        ->call('saveRecording')
        ->assertHasNoErrors();

    $upload = CandidateUploadFile::where('candidate_id', $candidate->id)->where('version_upload_file_id', $solo->id)->first();

    expect($upload->flagged_at)->toBeNull();
    expect($upload->flag_reason)->toBeNull();
});

test('saveRecording flags an upload whose duration is a clear outlier against the approved baseline for that slot', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $solo = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 1]);

    actingAs($teacher->user);

    // Seed an approved baseline of ~60-second Solo recordings.
    foreach ([58, 60, 62, 61, 59] as $priorDuration) {
        $priorCandidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
        CandidateUploadFile::create([
            'candidate_id' => $priorCandidate->id,
            'version_upload_file_id' => $solo->id,
            'url' => 'candidateUploads/'.$priorCandidate->id.'/solo.wav',
            'duration_seconds' => $priorDuration,
            'status' => CandidateUploadStatus::Approved->value,
            'uploaded_at' => now(),
            'decided_at' => now(),
            'decided_by_user_id' => $teacher->user->id,
        ]);
    }

    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $file = makeCandidateDetailWavFile(12.0, 'my recording.wav');

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('uploadRecording', $solo->id)
        ->set('newRecordingFile', $file)
        ->call('saveRecording')
        ->assertHasNoErrors();

    $upload = CandidateUploadFile::where('candidate_id', $candidate->id)->where('version_upload_file_id', $solo->id)->first();

    expect($upload->duration_seconds)->toBe(12);
    expect($upload->flagged_at)->not->toBeNull();
    expect($upload->flag_reason)->toContain('Solo');
});

test('the Recordings section shows a Review Suggested badge and the reason text for a flagged upload', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $solo = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $solo->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'original_filename' => 'Alto on scales.mp3',
        'flagged_at' => now(),
        'flag_reason' => 'The file name ("Alto on scales.mp3") mentions "Scales" rather than "Solo" — double-check it\'s in the right slot.',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertSee('Review Suggested')
        ->assertSee('mentions "Scales" rather than "Solo"');
});

test('re-uploading a recording replaces the S3 object and resets status to pending', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $existing = CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'url' => 'candidateUploads/'.$candidate->id.'/original.mp3',
        'status' => CandidateUploadStatus::Approved->value,
        'uploaded_at' => now(),
        'decided_at' => now(),
        'decided_by_user_id' => $teacher->user->id,
    ]);
    Storage::disk('s3')->put($existing->url, 'original-bytes');

    $file = UploadedFile::fake()->create('scales-take-2.mp3', 500, 'audio/mpeg');

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('uploadRecording', $slot->id)
        ->set('newRecordingFile', $file)
        ->call('saveRecording')
        ->assertHasNoErrors();

    expect(CandidateUploadFile::where('candidate_id', $candidate->id)->where('version_upload_file_id', $slot->id)->count())->toBe(1);
    $existing->refresh();
    expect($existing->getRawOriginal('status'))->toBe('pending');
    expect($existing->decided_at)->toBeNull();
    Storage::disk('s3')->assertMissing('candidateUploads/'.$candidate->id.'/original.mp3');
    Storage::disk('s3')->assertExists($existing->url);
});

test('approveRecording marks the upload approved and recalculates status', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value, 'emergency_contact_name' => false]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
    ]);
    $upload = CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'uploaded_by_user_id' => $teacher->user->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);
    Storage::disk('s3')->put($upload->url, 'bytes');

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('approveRecording', $upload->id);

    $upload->refresh();
    expect($upload->getRawOriginal('status'))->toBe('approved');
    expect($upload->decided_by_user_id)->toBe($teacher->user->id);
    expect($candidate->refresh()->status)->toBe(CandidateStatus::Registered);

    // Sync bridge: approving writes a matching row into `recordings` — the
    // only table the Adjudication/judging workflow reads from — keyed by
    // the slot's name so AdjudicationService::recordingsForCandidate() can
    // match it against score_categories.description.
    $recording = Recording::where('version_id', $version->id)->where('candidate_id', $candidate->id)->first();
    expect($recording)->not->toBeNull();
    expect($recording->file_type)->toBe('Scales');
    expect($recording->uploaded_by)->toBe($teacher->user->id);
    expect($recording->approved_by)->toBe($teacher->user->id);
    expect($recording->approved_at)->not->toBeNull();
    expect($recording->url)->toBe($upload->url);
});

test('approveRecording twice does not create a duplicate recordings row', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $upload = CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'uploaded_by_user_id' => $teacher->user->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);
    Storage::disk('s3')->put($upload->url, 'bytes');

    $component = Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate]);

    $component->call('approveRecording', $upload->id);
    $component->call('approveRecording', $upload->id);

    expect(Recording::where('version_id', $version->id)->where('candidate_id', $candidate->id)->count())->toBe(1);
});

test('rejecting a previously approved recording also deletes its synced recordings row', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $upload = CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'uploaded_by_user_id' => $teacher->user->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);
    Storage::disk('s3')->put($upload->url, 'bytes');

    $component = Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate]);

    $component->call('approveRecording', $upload->id);
    expect(Recording::where('version_id', $version->id)->where('candidate_id', $candidate->id)->exists())->toBeTrue();

    $component->call('rejectRecording', $upload->id);

    expect(CandidateUploadFile::find($upload->id))->toBeNull();
    expect(Recording::where('version_id', $version->id)->where('candidate_id', $candidate->id)->exists())->toBeFalse();
});

test('replacing an already-approved recording removes the stale synced recordings row', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $upload = CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'uploaded_by_user_id' => $teacher->user->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);
    Storage::disk('s3')->put($upload->url, 'bytes');

    $component = Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate]);

    $component->call('approveRecording', $upload->id);
    expect(Recording::where('version_id', $version->id)->where('candidate_id', $candidate->id)->exists())->toBeTrue();

    $newFile = UploadedFile::fake()->create('scales-take-2.mp3', 500, 'audio/mpeg');

    $component
        ->call('uploadRecording', $slot->id)
        ->set('newRecordingFile', $newFile)
        ->call('saveRecording')
        ->assertHasNoErrors();

    // The replaced upload reverts to pending — no synced recording should
    // remain until the new file is separately approved.
    $upload->refresh();
    expect($upload->getRawOriginal('status'))->toBe('pending');
    expect(Recording::where('version_id', $version->id)->where('candidate_id', $candidate->id)->exists())->toBeFalse();
});

test('rejectRecording deletes the S3 object and the row, re-opening the slot', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $upload = CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);
    Storage::disk('s3')->put($upload->url, 'bytes');

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('rejectRecording', $upload->id);

    expect(CandidateUploadFile::find($upload->id))->toBeNull();
    Storage::disk('s3')->assertMissing($upload->url);
});

test('approveRecording and rejectRecording 404 for an upload belonging to a different candidate', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $otherCandidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $otherUpload = CandidateUploadFile::create([
        'candidate_id' => $otherCandidate->id,
        'version_upload_file_id' => $slot->id,
        'url' => 'candidateUploads/'.$otherCandidate->id.'/scales.mp3',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('approveRecording', $otherUpload->id)
        ->assertStatus(404);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('rejectRecording', $otherUpload->id)
        ->assertStatus(404);
});

test('the upload/approval checklist items only appear for a remote Version with configured slots, and require every slot', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create([
        'audition_type' => AuditionType::Remote->value,
        'upload_type' => UploadType::Audio->value,
        'emergency_contact_name' => false,
    ]);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertSee('Upload of required audio files')
        ->assertSee('Teacher approval of required audio files');

    CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $slot->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('refreshStatus');

    // Uploaded but not yet approved — the upload item is satisfied, the
    // approval item is not, so overall status can't reach Registered.
    expect($candidate->refresh()->status)->toBe(CandidateStatus::Pending);
});

test('checklist labels are tailored to the Version\'s upload_type', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create([
        'audition_type' => AuditionType::Remote->value,
        'upload_type' => UploadType::Video->value,
    ]);
    VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 1]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertSee('Upload of required video files')
        ->assertSee('Teacher approval of required video files')
        ->assertDontSee('audio/video');
});

test('the Upload checklist item renders amber when some but not all slots have an upload', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Audio->value]);
    $scales = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);
    VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 2]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $scales->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp3',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);

    // Only one of the two slots has an upload — the upload item is "in
    // progress" (amber), not merely incomplete (red); the approval item
    // stays red since nothing uploaded has been approved yet.
    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertSeeHtml('bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400');
});

test('the Approval checklist item renders amber when some but not all uploaded files have been approved', function () {
    Storage::fake('s3');

    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create(['audition_type' => AuditionType::Remote->value, 'upload_type' => UploadType::Video->value]);
    $scales = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Scales', 'order_by' => 1]);
    $solo = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'Solo', 'order_by' => 2]);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    // Both slots uploaded (the Upload item is fully done — green), but only
    // one of the two uploads is approved.
    CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $scales->id,
        'url' => 'candidateUploads/'.$candidate->id.'/scales.mp4',
        'status' => CandidateUploadStatus::Approved->value,
        'uploaded_at' => now(),
        'decided_at' => now(),
        'decided_by_user_id' => $teacher->user->id,
    ]);
    CandidateUploadFile::create([
        'candidate_id' => $candidate->id,
        'version_upload_file_id' => $solo->id,
        'url' => 'candidateUploads/'.$candidate->id.'/solo.mp4',
        'status' => CandidateUploadStatus::Pending->value,
        'uploaded_at' => now(),
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertSeeHtml('bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400');
});

test('the Payment section is absent when the Version has no e-payment credential configured', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertDontSee('Payment')
        ->assertDontSee('Record Payment');
});

test('toggleEpaymentOptIn flips the teacher+Version opt-in and is scoped per teacher', function () {
    $teacher = makeCandidateDetailTeacher();
    $otherTeacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();
    EpaymentCredential::create(['version_id' => $version->id, 'epayment_id' => 'test-id', 'secret' => 'test-secret']);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('toggleEpaymentOptIn');

    $optIn = VersionTeacherEpaymentOptIn::where('version_id', $version->id)->where('teacher_id', $teacher->id)->first();
    expect($optIn->opted_in)->toBeTrue();

    // The other teacher's own state is untouched.
    expect(VersionTeacherEpaymentOptIn::where('version_id', $version->id)->where('teacher_id', $otherTeacher->id)->exists())->toBeFalse();
});

test('savePayment records a manual candidate_payments row with the amount converted to cents', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();
    EpaymentCredential::create(['version_id' => $version->id, 'epayment_id' => 'test-id', 'secret' => 'test-secret']);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('recordPayment')
        ->set('payment_amount', '125.50')
        ->set('payment_paid_at', now()->format('Y-m-d'))
        ->set('payment_reference_number', 'CONF-123')
        ->set('payment_comments', 'Paid at registration desk')
        ->call('savePayment')
        ->assertHasNoErrors();

    $payment = CandidatePayment::where('candidate_id', $candidate->id)->first();

    expect($payment)->not->toBeNull();
    expect($payment->amount)->toBe(12550);
    expect($payment->getRawOriginal('payment_type'))->toBe('electronic');
    expect($payment->reference_number)->toBe('CONF-123');
    expect($payment->comments)->toBe('Paid at registration desk');
});

test('savePayment requires a positive amount and a valid date', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();
    EpaymentCredential::create(['version_id' => $version->id, 'epayment_id' => 'test-id', 'secret' => 'test-secret']);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->call('recordPayment')
        ->set('payment_amount', '0')
        ->set('payment_paid_at', '')
        ->call('savePayment')
        ->assertHasErrors(['payment_amount', 'payment_paid_at']);
});

test('savePayment aborts with 404 when the Version has no e-payment credential', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->set('payment_amount', '50.00')
        ->set('payment_paid_at', now()->format('Y-m-d'))
        ->call('savePayment')
        ->assertStatus(404);
});

test('the payment history list shows previously recorded payments', function () {
    $teacher = makeCandidateDetailTeacher();
    $version = Version::factory()->create();
    EpaymentCredential::create(['version_id' => $version->id, 'epayment_id' => 'test-id', 'secret' => 'test-secret']);

    actingAs($teacher->user);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    CandidatePayment::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'payment_type' => 'electronic',
        'amount' => 5000,
        'reference_number' => 'REF-99',
        'paid_at' => now(),
    ]);

    Livewire::actingAs($teacher->user)
        ->test(CandidateDetail::class, ['version' => $version, 'candidate' => $candidate])
        ->assertSee('$50.00')
        ->assertSee('REF-99');
});
