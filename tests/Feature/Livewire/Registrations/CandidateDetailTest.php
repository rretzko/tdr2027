<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\CandidateStatus;
use App\Enums\EmergencyContactRelationship;
use App\Enums\VersionApplicationStatus;
use App\Livewire\Registrations\CandidateDetail;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
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
