<?php

declare(strict_types=1);

use App\Models\Candidate;
use App\Models\CoTeacherGrant;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Services\CoTeacherAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    // CandidateObserver writes a candidate_status_history row (user_id NOT
    // NULL) on every create — an authenticated user must be present even
    // though these tests exercise a service, not a request.
    actingAs(User::factory()->create());
});

function attachCoTeacherToSchool(Teacher $teacher, School $school, bool $isActive = true, bool $verified = true): void
{
    $teacher->schools()->attach($school->id, [
        'is_active' => $isActive,
        'verified_at' => $verified ? now() : null,
    ]);
}

function grantCoTeacherAccess(School $school, Teacher $granting, Teacher $coTeacher): CoTeacherGrant
{
    return CoTeacherGrant::create([
        'school_id' => $school->id,
        'granting_teacher_id' => $granting->id,
        'co_teacher_id' => $coTeacher->id,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

test('canAccessCandidate is true for a teacher\'s own candidate', function () {
    $teacher = Teacher::factory()->create();
    $candidate = Candidate::factory()->create(['teacher_id' => $teacher->id]);

    expect((new CoTeacherAccessService)->canAccessCandidate($teacher, $candidate))->toBeTrue();
});

test('canAccessCandidate is true for a co-teacher granted access at the candidate\'s school', function () {
    $school = School::factory()->create();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();
    $candidate = Candidate::factory()->create(['teacher_id' => $granting->id, 'school_id' => $school->id]);

    grantCoTeacherAccess($school, $granting, $coTeacher);

    expect((new CoTeacherAccessService)->canAccessCandidate($coTeacher, $candidate))->toBeTrue();
});

test('canAccessCandidate is false with no grant', function () {
    $teacher = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();
    $candidate = Candidate::factory()->create(['teacher_id' => $teacher->id]);

    expect((new CoTeacherAccessService)->canAccessCandidate($coTeacher, $candidate))->toBeFalse();
});

test('canAccessCandidate is false when the grant is scoped to a different school', function () {
    $grantedSchool = School::factory()->create();
    $candidateSchool = School::factory()->create();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();
    $candidate = Candidate::factory()->create(['teacher_id' => $granting->id, 'school_id' => $candidateSchool->id]);

    grantCoTeacherAccess($grantedSchool, $granting, $coTeacher);

    expect((new CoTeacherAccessService)->canAccessCandidate($coTeacher, $candidate))->toBeFalse();
});

test('canAccessCandidate is not reciprocal — a grant in one direction does not imply the other', function () {
    $school = School::factory()->create();
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();
    $candidateOfA = Candidate::factory()->create(['teacher_id' => $teacherA->id, 'school_id' => $school->id]);

    // A grants to B, but B never grants to A.
    grantCoTeacherAccess($school, $teacherA, $teacherB);

    $candidateOfB = Candidate::factory()->create(['teacher_id' => $teacherB->id, 'school_id' => $school->id]);

    $service = new CoTeacherAccessService;

    expect($service->canAccessCandidate($teacherB, $candidateOfA))->toBeTrue()
        ->and($service->canAccessCandidate($teacherA, $candidateOfB))->toBeFalse();
});

test('candidateQuery returns both a teacher\'s own and their granted candidates, and nothing else', function () {
    $school = School::factory()->create();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();
    $stranger = Teacher::factory()->create();

    $ownCandidate = Candidate::factory()->create(['teacher_id' => $coTeacher->id, 'school_id' => $school->id]);
    $grantedCandidate = Candidate::factory()->create(['teacher_id' => $granting->id, 'school_id' => $school->id]);
    $strangerCandidate = Candidate::factory()->create(['teacher_id' => $stranger->id, 'school_id' => $school->id]);

    grantCoTeacherAccess($school, $granting, $coTeacher);

    $ids = (new CoTeacherAccessService)->candidateQuery($coTeacher)->pluck('id');

    expect($ids)->toContain($ownCandidate->id);
    expect($ids)->toContain($grantedCandidate->id);
    expect($ids)->not->toContain($strangerCandidate->id);
});

test('candidateQuery is chainable with additional where clauses', function () {
    $school = School::factory()->create();
    $granting = Teacher::factory()->create();
    $coTeacher = Teacher::factory()->create();
    $version = Version::factory()->create();
    $otherVersion = Version::factory()->create();

    grantCoTeacherAccess($school, $granting, $coTeacher);

    $inVersion = Candidate::factory()->create(['teacher_id' => $granting->id, 'school_id' => $school->id, 'version_id' => $version->id]);
    Candidate::factory()->create(['teacher_id' => $granting->id, 'school_id' => $school->id, 'version_id' => $otherVersion->id]);

    $ids = (new CoTeacherAccessService)->candidateQuery($coTeacher)
        ->where('version_id', $version->id)
        ->pluck('id');

    expect($ids->all())->toBe([$inVersion->id]);
});

test('visibleTeacherIds includes the teacher\'s own id plus every granting teacher, deduped', function () {
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $grantingA = Teacher::factory()->create();
    $grantingB = Teacher::factory()->create();

    grantCoTeacherAccess($school, $grantingA, $teacher);
    grantCoTeacherAccess($school, $grantingB, $teacher);

    $ids = (new CoTeacherAccessService)->visibleTeacherIds($teacher);

    expect($ids->all())->toEqualCanonicalizing([$grantingA->id, $grantingB->id, $teacher->id]);
});

test('visibleTeacherIds can be narrowed to one school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $grantingA = Teacher::factory()->create();
    $grantingB = Teacher::factory()->create();

    grantCoTeacherAccess($schoolA, $grantingA, $teacher);
    grantCoTeacherAccess($schoolB, $grantingB, $teacher);

    $ids = (new CoTeacherAccessService)->visibleTeacherIds($teacher, $schoolA->id);

    expect($ids->all())->toEqualCanonicalizing([$grantingA->id, $teacher->id]);
});

test('grantableTeachers offers active+verified teachers at the school, excluding self and already-granted', function () {
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $candidateCoTeacher = Teacher::factory()->create();
    $alreadyGranted = Teacher::factory()->create();
    $inactiveTeacher = Teacher::factory()->create();
    $unverifiedTeacher = Teacher::factory()->create();

    attachCoTeacherToSchool($teacher, $school);
    attachCoTeacherToSchool($candidateCoTeacher, $school);
    attachCoTeacherToSchool($alreadyGranted, $school);
    attachCoTeacherToSchool($inactiveTeacher, $school, isActive: false);
    attachCoTeacherToSchool($unverifiedTeacher, $school, verified: false);

    grantCoTeacherAccess($school, $teacher, $alreadyGranted);

    $ids = (new CoTeacherAccessService)->grantableTeachers($teacher, $school)->pluck('id');

    expect($ids->all())->toBe([$candidateCoTeacher->id]);
});
