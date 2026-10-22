<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\CandidateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    actingAs(User::factory()->create());
});

test('enroll links the student\'s most recently updated emergency contact', function () {
    $student = Student::factory()->create();
    $older = EmergencyContact::factory()->create(['student_id' => $student->id]);
    $newer = EmergencyContact::factory()->create(['student_id' => $student->id]);
    $older->forceFill(['updated_at' => Carbon::now()->addDay()])->save();

    $candidate = (new CandidateService)->enroll(
        Version::factory()->create(),
        $student,
        Teacher::factory()->create(),
        School::factory()->create()->id,
        VoicePart::factory()->create()->id,
    );

    expect($candidate->refresh()->emergency_contact_id)->toBe($older->id)
        ->and($newer->id)->not->toBe($older->id);
});

test('enroll leaves the link null when the student has no emergency contact', function () {
    $candidate = (new CandidateService)->enroll(
        Version::factory()->create(),
        Student::factory()->create(),
        Teacher::factory()->create(),
        School::factory()->create()->id,
        VoicePart::factory()->create()->id,
    );

    expect($candidate->refresh()->emergency_contact_id)->toBeNull();
});

test('creating an emergency contact links it to the student\'s unlinked candidates only', function () {
    $student = Student::factory()->create();
    $unlinked = Candidate::factory()->create(['student_id' => $student->id]);
    $existing = EmergencyContact::factory()->create(['student_id' => $student->id]);
    $alreadyLinked = Candidate::factory()->create(['student_id' => $student->id, 'emergency_contact_id' => $existing->id]);
    $otherStudentCandidate = Candidate::factory()->create();

    // Creating $existing above already linked $unlinked; reset to exercise the new contact.
    Candidate::whereKey($unlinked->id)->update(['emergency_contact_id' => null]);

    $contact = EmergencyContact::factory()->create(['student_id' => $student->id]);

    expect($unlinked->refresh()->emergency_contact_id)->toBe($contact->id)
        ->and($alreadyLinked->refresh()->emergency_contact_id)->toBe($existing->id)
        ->and($otherStudentCandidate->refresh()->emergency_contact_id)->toBeNull();
});

test('deleting a linked emergency contact re-points candidates at the most recently updated remaining contact', function () {
    $student = Student::factory()->create();
    $remaining = EmergencyContact::factory()->create(['student_id' => $student->id]);
    $removed = EmergencyContact::factory()->create(['student_id' => $student->id]);
    $candidate = Candidate::factory()->create(['student_id' => $student->id, 'emergency_contact_id' => $removed->id]);

    $removed->delete();

    expect($candidate->refresh()->emergency_contact_id)->toBe($remaining->id);
});

test('deleting a student\'s only emergency contact clears the candidate link', function () {
    $student = Student::factory()->create();
    $contact = EmergencyContact::factory()->create(['student_id' => $student->id]);
    $candidate = Candidate::factory()->create(['student_id' => $student->id, 'emergency_contact_id' => $contact->id]);

    $contact->delete();

    expect($candidate->refresh()->emergency_contact_id)->toBeNull();
});
