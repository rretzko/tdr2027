<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\PhoneType;
use App\Livewire\Events\Reports\ParticipatingCandidates;
use App\Models\Candidate;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\Ensemble;
use App\Models\Phone;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * Attaches a new VoicePart to a new Ensemble under $version's Event, so it
 * appears in Version::availableVoiceParts() and can be selected on the edit
 * form.
 */
function makeAvailableVoicePart(Version $version): VoicePart
{
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $voicePart = VoicePart::factory()->create();
    $ensemble->voiceParts()->attach($voicePart->id);

    return $voicePart;
}

function makeParticipatingCandidate(Version $version, ?County $county = null): Candidate
{
    $county ??= County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    $teacher = Teacher::factory()->create();
    $voicePart = makeAvailableVoicePart($version);

    return Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'voice_part_id' => $voicePart->id,
    ]);
}

test('mount aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(ParticipatingCandidates::class, ['version' => $version])
        ->assertStatus(403);
});

test('lists a registered candidate', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $candidate = makeParticipatingCandidate($version);

    Livewire::actingAs($founder)
        ->test(ParticipatingCandidates::class, ['version' => $version])
        ->assertOk()
        ->assertSee($candidate->student->user->name);
});

test('a Co-Registration Manager only sees candidates within their assigned county', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    $candidateInA = makeParticipatingCandidate($version, $countyA);
    $candidateInB = makeParticipatingCandidate($version, $countyB);

    $coRegManager = User::factory()->create();
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $coRegManager->id, 'county_id' => $countyA->id]);

    Livewire::actingAs($coRegManager)
        ->test(ParticipatingCandidates::class, ['version' => $version])
        ->assertOk()
        ->assertSee($candidateInA->student->user->name)
        ->assertDontSee($candidateInB->student->user->name);
});

test('edit populates the form fields from the candidate', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $candidate = makeParticipatingCandidate($version);
    $studentUser = $candidate->student->user;
    $studentUser->update(['first_name' => 'Jamie', 'last_name' => 'Rivera']);
    Phone::create(['user_id' => $studentUser->id, 'type' => PhoneType::Home->value, 'raw_number' => '5551234567']);

    Livewire::actingAs($founder)
        ->test(ParticipatingCandidates::class, ['version' => $version])
        ->call('edit', $candidate->id)
        ->assertSet('edit_first_name', 'Jamie')
        ->assertSet('edit_last_name', 'Rivera')
        ->assertSet('edit_home_phone', '5551234567');
});

test('save updates the candidate\'s name, voice part, phones, and emergency contact', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $candidate = makeParticipatingCandidate($version);
    $newVoicePart = makeAvailableVoicePart($version);
    $contact = \App\Models\EmergencyContact::factory()->create(['student_id' => $candidate->student_id]);

    Livewire::actingAs($founder)
        ->test(ParticipatingCandidates::class, ['version' => $version])
        ->call('edit', $candidate->id)
        ->set('edit_first_name', 'Morgan')
        ->set('edit_last_name', 'Lee')
        ->set('edit_voice_part_id', (string) $newVoicePart->id)
        ->set('edit_home_phone', '5559876543')
        ->set('edit_cell_phone', '5551112222')
        ->set('edit_emergency_contact_id', (string) $contact->id)
        ->call('save')
        ->assertHasNoErrors();

    $candidate->refresh();
    $studentUser = $candidate->student->user->refresh();

    expect($studentUser->first_name)->toBe('Morgan');
    expect($studentUser->last_name)->toBe('Lee');
    expect($studentUser->cell_phone)->toBe('5551112222');
    expect(Phone::where('user_id', $studentUser->id)->where('type', PhoneType::Home->value)->value('raw_number'))->toBe('5559876543');
    expect($candidate->voice_part_id)->toBe($newVoicePart->id);
    expect($candidate->emergency_contact_id)->toBe($contact->id);
});

test('remove transitions the candidate to withdrew and records status history', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $candidate = makeParticipatingCandidate($version);

    Livewire::actingAs($founder)
        ->test(ParticipatingCandidates::class, ['version' => $version])
        ->call('remove', $candidate->id, 'withdrew');

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Withdrew);
    expect(\App\Models\CandidateStatusHistory::where('candidate_id', $candidate->id)->where('to_status', 'withdrew')->exists())->toBeTrue();
});

test('remove transitions the candidate to teacher_withdrawn', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $candidate = makeParticipatingCandidate($version);

    Livewire::actingAs($founder)
        ->test(ParticipatingCandidates::class, ['version' => $version])
        ->call('remove', $candidate->id, 'teacher_withdrawn');

    expect($candidate->refresh()->status)->toBe(CandidateStatus::TeacherWithdrawn);
});

test('remove rejects a status other than withdrew or teacher_withdrawn', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $candidate = makeParticipatingCandidate($version);

    Livewire::actingAs($founder)
        ->test(ParticipatingCandidates::class, ['version' => $version])
        ->call('remove', $candidate->id, 'accepted')
        ->assertStatus(400);
});

test('edit aborts with 404 for a candidate outside the acting user\'s county scope', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    $candidateInB = makeParticipatingCandidate($version, $countyB);

    $coRegManager = User::factory()->create();
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $coRegManager->id, 'county_id' => $countyA->id]);

    // A mid-action firstOrFail() doesn't convert to an HTTP response inside
    // Livewire::test()->call() the way a mount-time abort does (see
    // VersionScoringRubricTest for the same pattern) — assert the thrown
    // exception directly instead of the HTTP status.
    $component = Livewire::actingAs($coRegManager)
        ->test(ParticipatingCandidates::class, ['version' => $version]);

    expect(fn () => $component->call('edit', $candidateInB->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('PDF export returns a PDF for an authorized user', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    makeParticipatingCandidate($version);

    get(route('events.versions.reports.participating-candidates.pdf', $version))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('PDF export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.participating-candidates.pdf', $version))
        ->assertForbidden();
});
