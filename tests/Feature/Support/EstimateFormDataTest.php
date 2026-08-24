<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionFee;
use App\Services\MailToAddressResolver;
use App\Support\EstimateFormData;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * @return array{teacher: Teacher, version: Version, school: School}
 */
function makeEstimateFormDataScenario(): array
{
    $teacherUser = User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id, 'onboarding_completed_at' => now()]);
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 3000]);

    actingAs($teacherUser);

    return compact('teacher', 'version', 'school');
}

/**
 * TestCase seeds the lookup tables before every test (voice_parts included),
 * so ids 1-7 are the real "upper voice" parts (Descant..Alto II — see
 * EstimateFormData::UPPER_VOICE_PART_IDS) and 8 is Tenor, a "lower voice"
 * stand-in.
 *
 * @return array{upper: list<int>, lower: int}
 */
function makeEstimateFormVoicePartIds(): array
{
    return ['upper' => [1, 2, 3, 4, 5, 6, 7], 'lower' => 8];
}

function makeEstimateFormCandidate(Version $version, School $school, Teacher $teacher, int $voicePartId, string $lastName): void
{
    $user = User::factory()->create(['first_name' => 'X', 'last_name' => $lastName]);
    $student = Student::factory()->create(['user_id' => $user->id]);

    Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'student_id' => $student->id,
        'voice_part_id' => $voicePartId,
    ]);
}

test('max_upper_voice_registrants of 0 applies no upper-voice cap', function () {
    ['teacher' => $teacher, 'version' => $version, 'school' => $school] = makeEstimateFormDataScenario();
    $voiceParts = makeEstimateFormVoicePartIds();
    $version->update(['max_upper_voice_registrants' => 0]);

    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['upper'][0], 'Adams');
    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['upper'][1], 'Baker');
    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['upper'][2], 'Carter');

    $data = EstimateFormData::build($version, $school, $teacher, app(MailToAddressResolver::class));

    expect($data->candidates)->toHaveCount(3);
    expect($data->truncated)->toBeFalse();
});

test('max_upper_voice_registrants caps only upper-voice candidates, leaving lower-voice candidates untouched', function () {
    ['teacher' => $teacher, 'version' => $version, 'school' => $school] = makeEstimateFormDataScenario();
    $voiceParts = makeEstimateFormVoicePartIds();
    $version->update(['max_upper_voice_registrants' => 1]);

    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['upper'][0], 'Adams');
    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['upper'][1], 'Baker');
    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['lower'], 'Carter');

    $data = EstimateFormData::build($version, $school, $teacher, app(MailToAddressResolver::class));

    expect($data->candidates->pluck('student.user.last_name')->all())->toBe(['Adams', 'Carter']);
    expect($data->truncated)->toBeTrue();
});

test('max_upper_voice_registrants is applied before max_registrants', function () {
    ['teacher' => $teacher, 'version' => $version, 'school' => $school] = makeEstimateFormDataScenario();
    $voiceParts = makeEstimateFormVoicePartIds();
    $version->update(['max_upper_voice_registrants' => 1, 'max_registrants' => 2]);

    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['upper'][0], 'Adams');
    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['upper'][1], 'Baker');
    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['lower'], 'Carter');
    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['lower'], 'Davis');

    $data = EstimateFormData::build($version, $school, $teacher, app(MailToAddressResolver::class));

    // Upper-voice cap (1) first drops Baker, leaving Adams, Carter, Davis;
    // max_registrants (2) then drops Davis.
    expect($data->candidates->pluck('student.user.last_name')->all())->toBe(['Adams', 'Carter']);
    expect($data->truncated)->toBeTrue();
});

test('max_registrants alone still truncates when max_upper_voice_registrants is null', function () {
    ['teacher' => $teacher, 'version' => $version, 'school' => $school] = makeEstimateFormDataScenario();
    $voiceParts = makeEstimateFormVoicePartIds();
    $version->update(['max_registrants' => 1]);

    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['lower'], 'Adams');
    makeEstimateFormCandidate($version, $school, $teacher, $voiceParts['lower'], 'Baker');

    $data = EstimateFormData::build($version, $school, $teacher, app(MailToAddressResolver::class));

    expect($data->candidates->pluck('student.user.last_name')->all())->toBe(['Adams']);
    expect($data->truncated)->toBeTrue();
});
