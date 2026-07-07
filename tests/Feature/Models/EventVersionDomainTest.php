<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\Frequency;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Enums\VersionDateType;
use App\Models\Candidate;
use App\Models\CandidateStatusHistory;
use App\Models\County;
use App\Models\Ensemble;
use App\Models\EnsembleGrade;
use App\Models\Event;
use App\Models\EventGrade;
use App\Models\Organization;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionCounty;
use App\Models\VersionDate;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionFee;
use App\Models\VersionMembershipRequirement;
use App\Models\VersionTimeslot;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('Event belongs to an Organization and casts status/frequency to enums', function () {
    $organization = Organization::factory()->create();
    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'status' => EventStatus::Active,
        'frequency' => Frequency::Biennial,
    ]);

    expect($event->organization->is($organization))->toBeTrue();
    expect($event->status)->toBe(EventStatus::Active);
    expect($event->frequency)->toBe(Frequency::Biennial);
});

test('Event has many Versions, Ensembles, and EventGrades', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    EventGrade::create(['event_id' => $event->id, 'grade' => 9]);

    expect($event->versions->pluck('id'))->toContain($version->id);
    expect($event->ensembles->pluck('id'))->toContain($ensemble->id);
    expect($event->grades)->toHaveCount(1);
    expect($event->grades->first()->grade)->toBe(9);
});

test('EventGrade belongs to its Event', function () {
    $event = Event::factory()->create();
    $grade = EventGrade::create(['event_id' => $event->id, 'grade' => 10]);

    expect($grade->event->is($event))->toBeTrue();
});

test('Version casts its enum-backed columns and booleans', function () {
    $version = Version::factory()->create([
        'status' => EventStatus::Sandbox,
        'application_type' => ApplicationType::EApplication,
        'audition_type' => AuditionType::InPerson,
        'pitch_file_visibility' => PitchFileVisibility::Teacher,
        'score_order' => ScoreOrder::Desc,
        'upload_type' => UploadType::Audio,
        'birthday' => true,
    ]);

    expect($version->status)->toBe(EventStatus::Sandbox);
    expect($version->application_type)->toBe(ApplicationType::EApplication);
    expect($version->audition_type)->toBe(AuditionType::InPerson);
    expect($version->pitch_file_visibility)->toBe(PitchFileVisibility::Teacher);
    expect($version->score_order)->toBe(ScoreOrder::Desc);
    expect($version->upload_type)->toBe(UploadType::Audio);
    expect($version->birthday)->toBeTrue();
});

test('Version belongs to an Event and has many Candidates', function () {
    actingAs(User::factory()->create());

    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    $candidate = Candidate::factory()->create(['version_id' => $version->id]);

    expect($version->event->is($event))->toBeTrue();
    expect($version->candidates->pluck('id'))->toContain($candidate->id);
});

test('Version has one VersionFee and one VersionMembershipRequirement', function () {
    $version = Version::factory()->create();

    $fee = VersionFee::create([
        'version_id' => $version->id,
        'registration' => 2000,
        'on_site_registration' => 0,
        'participation' => 5000,
        'epayment_surcharge' => 0,
        'housing' => 0,
    ]);

    $requirement = VersionMembershipRequirement::create([
        'version_id' => $version->id,
        'membership_card' => true,
        'valid_thru' => '2027-06-30',
    ]);

    expect($version->fresh()->fees->is($fee))->toBeTrue();
    expect($version->fresh()->membershipRequirement->is($requirement))->toBeTrue();
    expect($fee->registrationInDollars())->toBe(20.0);
    expect($fee->participationInDollars())->toBe(50.0);
});

test('Version has many VersionDates and casts date_type to an enum', function () {
    $version = Version::factory()->create();

    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Teacher->value,
        'start_at' => now(),
        'end_at' => null,
    ]);

    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Adjudication->value,
        'start_at' => now(),
        'end_at' => now()->addDay(),
    ]);

    $dates = $version->fresh()->dates;

    expect($dates)->toHaveCount(2);
    expect($dates->first()->date_type)->toBeInstanceOf(VersionDateType::class);
});

test('Version has many VersionCounty rows tied to counties', function () {
    $version = Version::factory()->create();
    $county = County::factory()->create();

    VersionCounty::create(['version_id' => $version->id, 'county_id' => $county->id]);

    expect($version->fresh()->counties)->toHaveCount(1);
    expect($version->fresh()->counties->first()->county->is($county))->toBeTrue();
});

test('Version ensembleOrder is ordered by order_by ascending', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id]);
    $first = Ensemble::factory()->create(['event_id' => $event->id]);
    $second = Ensemble::factory()->create(['event_id' => $event->id]);

    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $second->id, 'order_by' => 1]);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $first->id, 'order_by' => 2]);

    $ordered = $version->fresh()->ensembleOrder;

    expect($ordered->pluck('ensemble_id')->all())->toBe([$second->id, $first->id]);
});

test('Version timeslots are ordered chronologically', function () {
    $version = Version::factory()->create();
    $laterSchool = School::factory()->create();
    $earlierSchool = School::factory()->create();

    VersionTimeslot::create(['version_id' => $version->id, 'school_id' => $laterSchool->id, 'timeslot' => now()->addHour()]);
    VersionTimeslot::create(['version_id' => $version->id, 'school_id' => $earlierSchool->id, 'timeslot' => now()]);

    $timeslots = $version->fresh()->timeslots;

    $first = strtotime((string) $timeslots->first()->getRawOriginal('timeslot'));
    $last = strtotime((string) $timeslots->last()->getRawOriginal('timeslot'));

    expect($first)->toBeLessThan($last);
});

test('Version uploadFiles are ordered by order_by and uploadFileCount is derived, not stored', function () {
    $version = Version::factory()->create();

    VersionUploadFile::create(['version_id' => $version->id, 'name' => 'solo', 'order_by' => 2]);
    VersionUploadFile::create(['version_id' => $version->id, 'name' => 'scales', 'order_by' => 1]);
    VersionUploadFile::create(['version_id' => $version->id, 'name' => 'quintet', 'order_by' => 3]);

    $fresh = $version->fresh();

    expect($fresh->uploadFiles->pluck('name')->all())->toBe(['scales', 'solo', 'quintet']);
    expect($fresh->upload_file_count)->toBe(3);
});

test('Ensemble belongs to an Event, has many EnsembleGrades, and belongs to many VoiceParts', function () {
    $event = Event::factory()->create();
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id]);
    $voicePart = VoicePart::factory()->create(['sort_order' => 99]);

    EnsembleGrade::create(['ensemble_id' => $ensemble->id, 'grade' => 11]);
    $ensemble->voiceParts()->attach($voicePart->id);

    expect($ensemble->event->is($event))->toBeTrue();
    expect($ensemble->grades)->toHaveCount(1);
    expect($ensemble->fresh()->voiceParts->pluck('id'))->toContain($voicePart->id);
});

test('EnsembleGrade belongs to its Ensemble', function () {
    $ensemble = Ensemble::factory()->create();
    $grade = EnsembleGrade::create(['ensemble_id' => $ensemble->id, 'grade' => 8]);

    expect($grade->ensemble->is($ensemble))->toBeTrue();
});

test('creating a Candidate assigns a composite id and a hyphenated ref via the observer', function () {
    actingAs(User::factory()->create());

    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['version_id' => $version->id]);

    expect((string) $candidate->id)->toStartWith((string) $version->id);
    expect($candidate->ref)->toBe($version->id.'-'.substr((string) $candidate->id, strlen((string) $version->id)));
});

test('creating a Candidate defaults program_name to the student\'s name when blank', function () {
    actingAs(User::factory()->create());

    $studentUser = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $student = Student::factory()->create(['user_id' => $studentUser->id]);
    $version = Version::factory()->create();

    $candidate = Candidate::create([
        'student_id' => $student->id,
        'version_id' => $version->id,
        'school_id' => School::factory()->create()->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'voice_part_id' => VoicePart::factory()->create()->id,
        'status' => CandidateStatus::Eligible->value,
        'program_name' => '',
        'emergency_contact_id' => null,
    ]);

    expect($candidate->program_name)->toBe('Ada Lovelace');
});

test('creating a Candidate records an initial CandidateStatusHistory entry with a null from_status', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create();

    $history = CandidateStatusHistory::where('candidate_id', $candidate->id)->get();

    expect($history)->toHaveCount(1);
    expect($history->first()->from_status)->toBeNull();
    expect($history->first()->to_status)->toBe(CandidateStatus::Eligible);
});

test('changing a Candidate status records a CandidateStatusHistory transition with the acting user', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create(['status' => CandidateStatus::Eligible]);

    $user = User::factory()->create();
    actingAs($user);
    $candidate->update(['status' => CandidateStatus::Registered]);

    $history = CandidateStatusHistory::where('candidate_id', $candidate->id)
        ->orderBy('created_at')
        ->get();

    expect($history)->toHaveCount(2);
    expect($history->last()->from_status)->toBe(CandidateStatus::Eligible);
    expect($history->last()->to_status)->toBe(CandidateStatus::Registered);
    expect($history->last()->user_id)->toBe($user->id);
});

test('updating a Candidate without changing status does not record a new CandidateStatusHistory entry', function () {
    actingAs(User::factory()->create());

    $candidate = Candidate::factory()->create(['program_name' => 'Original Name']);

    $candidate->update(['program_name' => 'Updated Name']);

    expect(CandidateStatusHistory::where('candidate_id', $candidate->id)->count())->toBe(1);
});
