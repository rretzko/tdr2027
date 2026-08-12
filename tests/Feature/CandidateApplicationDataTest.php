<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Support\CandidateApplicationData;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('fromCandidate formats teacher, student, and emergency contact phone numbers as (###) ###-#### x###', function () {
    $version = Version::factory()->create();

    $teacherUser = User::factory()->create(['cell_phone' => '2015551234']);
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

    // CandidateObserver::created() writes a candidate_status_history row
    // with user_id = Auth::id(), which is NOT NULL — needs an authenticated
    // user in place before the insert.
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
    ]);
    $candidate->student->user->update(['cell_phone' => '9735559876']);

    $emergencyContact = EmergencyContact::factory()->create([
        'student_id' => $candidate->student_id,
        // Raw digits plus an extension, exactly as PhoneNormalizer would
        // have stored it on save — fromCandidate() must format this for
        // display, not pass the normalized digits through untouched.
        'cell_phone' => '60155512341234',
    ]);
    $candidate->update(['emergency_contact_id' => $emergencyContact->id]);

    $data = CandidateApplicationData::fromCandidate($candidate->load([
        'student.user', 'student.emergencyContacts', 'teacher.user', 'school', 'voicePart', 'version.fees', 'version.dates', 'version.event.organization',
    ]));

    expect($data->teacherCellPhone)->toBe('(201) 555-1234');
    expect($data->studentCellPhone)->toBe('(973) 555-9876');
    expect($data->emergencyContactPhone)->toBe('(601) 555-1234 x1234');
});

test('fromCandidate falls back to an em dash when a phone number is missing', function () {
    $version = Version::factory()->create();

    $teacherUser = User::factory()->create(['cell_phone' => null]);
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
    ]);
    $candidate->student->user->update(['cell_phone' => null]);

    $data = CandidateApplicationData::fromCandidate($candidate->load([
        'student.user', 'student.emergencyContacts', 'teacher.user', 'school', 'voicePart', 'version.fees', 'version.dates', 'version.event.organization',
    ]));

    expect($data->teacherCellPhone)->toBe('—');
    expect($data->studentCellPhone)->toBe('—');
    expect($data->emergencyContactPhone)->toBe('—');
});

test('tokenDescriptions keys exactly match toTokenMap keys', function () {
    $version = Version::factory()->create();
    $data = CandidateApplicationData::placeholder($version);

    expect(array_keys(CandidateApplicationData::tokenDescriptions()))
        ->toEqualCanonicalizing(array_keys($data->toTokenMap()));
});

test('tokenDescriptions is sorted by token name', function () {
    $keys = array_keys(CandidateApplicationData::tokenDescriptions());

    $sorted = $keys;
    sort($sorted);

    expect($keys)->toEqual($sorted);
});
