<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\CoTeacherGrant;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionCoTeacherConsolidation;
use App\Models\VoicePart;
use App\Services\CandidateService;
use App\Services\CoTeacherConsolidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    // CandidateObserver writes a candidate_status_history row (user_id NOT
    // NULL) on every create — an authenticated user must be present even
    // though these tests exercise a service, not a request.
    actingAs(User::factory()->create());
});

function grantConsolidationCoTeacherAccess(School $school, Teacher $granting, Teacher $coTeacher): CoTeacherGrant
{
    return CoTeacherGrant::create([
        'school_id' => $school->id,
        'granting_teacher_id' => $granting->id,
        'co_teacher_id' => $coTeacher->id,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

test('set() canonicalizes first_teacher_id/second_teacher_id regardless of argument order', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();
    $setByUser = User::factory()->create();

    // Call once with A, B and once (a fresh pairing) with B, A — both must
    // land on the same canonical (first, second) ordering.
    (new CoTeacherConsolidationService)->set($version, $school, $teacherA, $teacherB, $teacherA, $setByUser);

    [$expectedFirst, $expectedSecond] = $teacherA->id < $teacherB->id ? [$teacherA->id, $teacherB->id] : [$teacherB->id, $teacherA->id];

    $row = VersionCoTeacherConsolidation::where('version_id', $version->id)->where('school_id', $school->id)->first();

    expect($row->first_teacher_id)->toBe($expectedFirst);
    expect($row->second_teacher_id)->toBe($expectedSecond);
    expect($row->consolidated_teacher_id)->toBe($teacherA->id);
});

test('set() retroactively reassigns existing candidates at that school/version to the consolidated teacher', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();

    $ownedByA = Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacherA->id]);
    $ownedByB = Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacherB->id]);
    $alreadyOwnedByA = Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacherA->id]);

    (new CoTeacherConsolidationService)->set($version, $school, $teacherA, $teacherB, $teacherA, User::factory()->create());

    expect($ownedByA->refresh()->teacher_id)->toBe($teacherA->id);
    expect($ownedByB->refresh()->teacher_id)->toBe($teacherA->id);
    expect($alreadyOwnedByA->refresh()->teacher_id)->toBe($teacherA->id);
});

test('set() does not touch candidates at a different school or a different Version', function () {
    $version = Version::factory()->create();
    $otherVersion = Version::factory()->create();
    $school = School::factory()->create();
    $otherSchool = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();

    $wrongSchool = Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $otherSchool->id, 'teacher_id' => $teacherB->id]);
    $wrongVersion = Candidate::factory()->create(['version_id' => $otherVersion->id, 'school_id' => $school->id, 'teacher_id' => $teacherB->id]);

    (new CoTeacherConsolidationService)->set($version, $school, $teacherA, $teacherB, $teacherA, User::factory()->create());

    expect($wrongSchool->refresh()->teacher_id)->toBe($teacherB->id);
    expect($wrongVersion->refresh()->teacher_id)->toBe($teacherB->id);
});

test('set() rejects a consolidated teacher who isn\'t one of the pair', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();
    $stranger = Teacher::factory()->create();

    expect(fn () => (new CoTeacherConsolidationService)->set($version, $school, $teacherA, $teacherB, $stranger, User::factory()->create()))
        ->toThrow(HttpException::class);
});

test('resolveTeacherId returns the natural teacher when no consolidation is set', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();

    $resolved = (new CoTeacherConsolidationService)->resolveTeacherId($version, $school->id, $teacher);

    expect($resolved->id)->toBe($teacher->id);
});

test('resolveTeacherId redirects to the consolidated teacher when the natural teacher is the other side', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();

    (new CoTeacherConsolidationService)->set($version, $school, $teacherA, $teacherB, $teacherA, User::factory()->create());

    $resolved = (new CoTeacherConsolidationService)->resolveTeacherId($version, $school->id, $teacherB);

    expect($resolved->id)->toBe($teacherA->id);
});

test('resolveTeacherId returns the natural teacher unchanged when they are already the consolidated one', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();

    (new CoTeacherConsolidationService)->set($version, $school, $teacherA, $teacherB, $teacherA, User::factory()->create());

    $resolved = (new CoTeacherConsolidationService)->resolveTeacherId($version, $school->id, $teacherA);

    expect($resolved->id)->toBe($teacherA->id);
});

test('resolveTeacherId is scoped per school — a consolidation at one school does not apply at another', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $otherSchool = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();

    (new CoTeacherConsolidationService)->set($version, $school, $teacherA, $teacherB, $teacherA, User::factory()->create());

    $resolved = (new CoTeacherConsolidationService)->resolveTeacherId($version, $otherSchool->id, $teacherB);

    expect($resolved->id)->toBe($teacherB->id);
});

test('CandidateService::enroll() records a new candidate under the consolidated teacher, not the natural one', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();
    $student = Student::factory()->create();
    $voicePart = VoicePart::factory()->create();

    (new CoTeacherConsolidationService)->set($version, $school, $teacherA, $teacherB, $teacherA, User::factory()->create());

    $candidate = (new CandidateService)->enroll($version, $student, $teacherB, $school->id, $voicePart->id);

    expect($candidate->teacher_id)->toBe($teacherA->id);
});

test('relevantPairings includes a school where a grant exists and at least one side has a candidate in this Version', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();

    grantConsolidationCoTeacherAccess($school, $teacher, $coTeacher);
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id]);

    $pairings = (new CoTeacherConsolidationService)->relevantPairings($teacher, $version);

    expect($pairings)->toHaveCount(1);
    expect($pairings->first()['school']->id)->toBe($school->id);
    expect($pairings->first()['otherTeacher']->id)->toBe($coTeacher->id);
    expect($pairings->first()['existing'])->toBeNull();
});

test('relevantPairings excludes a grant with no candidates at that school in this Version yet', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();

    grantConsolidationCoTeacherAccess($school, $teacher, $coTeacher);
    // No Candidate created at all.

    $pairings = (new CoTeacherConsolidationService)->relevantPairings($teacher, $version);

    expect($pairings)->toHaveCount(0);
});

test('relevantPairings surfaces the existing consolidation row once one has been set', function () {
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();

    grantConsolidationCoTeacherAccess($school, $teacher, $coTeacher);
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id]);

    (new CoTeacherConsolidationService)->set($version, $school, $teacher, $coTeacher, $coTeacher, User::factory()->create());

    $pairings = (new CoTeacherConsolidationService)->relevantPairings($teacher, $version);

    expect($pairings->first()['existing'])->not->toBeNull();
    expect($pairings->first()['existing']->consolidated_teacher_id)->toBe($coTeacher->id);
});
