<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Enums\ObligationDecision;
use App\Enums\PaymentTransactionStatus;
use App\Enums\VersionInvitationStatus;
use App\Enums\VersionObligationStatus;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionFee;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionObligationResponse;
use App\Services\VersionScorecardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    // CandidateObserver writes candidate_status_history rows keyed by
    // Auth::id() on every create/status change — see EligibilityServiceTest's
    // equivalent setup.
    actingAs(User::factory()->create());
});

test('metricsFor counts invited teachers, schools, and eligible students, including a student already registered as a Candidate', function () {
    $version = Version::factory()->create();
    $students = [];

    foreach (range(1, 2) as $i) {
        $school = School::factory()->create();
        $teacher = Teacher::factory()->create();
        $student = Student::factory()->create();

        $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
        $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => (int) date('Y') + 1]);
        $student->teachers()->attach($teacher->id, ['school_id' => $school->id, 'subject' => 'chorus', 'role' => 'primary', 'is_active' => true]);

        VersionInvitation::create([
            'version_id' => $version->id,
            'teacher_id' => $teacher->id,
            'status' => VersionInvitationStatus::Invited->value,
            'invited_at' => now(),
            'invited_by_user_id' => User::factory()->create()->id,
        ]);

        $students[$i] = ['student' => $student, 'school' => $school, 'teacher' => $teacher];
    }

    // A Version filling up with Candidates must not collapse the Invited
    // Students count toward zero — see EligibilityService::eligibleStudents()'s
    // $excludeEnrolled param, which the scorecard opts out of.
    Candidate::factory()->create([
        'version_id' => $version->id,
        'student_id' => $students[1]['student']->id,
        'school_id' => $students[1]['school']->id,
        'teacher_id' => $students[1]['teacher']->id,
        'status' => CandidateStatus::Registered,
    ]);

    $metrics = app(VersionScorecardService::class)->metricsFor($version);

    expect($metrics->invitedTeachers)->toBe(2)
        ->and($metrics->invitedSchools)->toBe(2)
        ->and($metrics->eligibleStudents)->toBe(2);
});

test('metricsFor counts obligated teachers and their schools, excluding a merely-invited teacher', function () {
    $version = Version::factory()->create();

    $school = School::factory()->create();
    $obligatedTeacher = Teacher::factory()->create();
    $obligatedTeacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);

    $invitation = VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $obligatedTeacher->id,
        'status' => VersionInvitationStatus::Invited->value,
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
        'decision' => ObligationDecision::Accepted->value,
        'decided_at' => now(),
        'obligation_snapshot' => $obligation->body,
    ]);

    $invitedOnlyTeacher = Teacher::factory()->create();
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $invitedOnlyTeacher->id,
        'status' => VersionInvitationStatus::Invited->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    $metrics = app(VersionScorecardService::class)->metricsFor($version);

    expect($metrics->obligatedTeachers)->toBe(1)
        ->and($metrics->obligatedSchools)->toBe(1);
});

test('metricsFor counts engaged students as pending or registered candidates only', function () {
    $version = Version::factory()->create();

    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Pending]);
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Registered]);
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Eligible]);
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Withdrew]);

    $metrics = app(VersionScorecardService::class)->metricsFor($version);

    expect($metrics->engagedStudents)->toBe(2);
});

test('metricsFor counts unique registered teachers and schools, and total registered students', function () {
    $version = Version::factory()->create();
    $teacher = Teacher::factory()->create();
    $school = School::factory()->create();

    Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'status' => CandidateStatus::Registered,
    ]);
    Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'status' => CandidateStatus::Registered,
    ]);
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Eligible]);

    $metrics = app(VersionScorecardService::class)->metricsFor($version);

    expect($metrics->registeredStudents)->toBe(2)
        ->and($metrics->registeredTeachers)->toBe(1)
        ->and($metrics->registeredSchools)->toBe(1);
});

test('metricsFor computes registration fees due, paid, and outstanding without double-counting the parent transaction amount', function () {
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000]);

    $candidateOne = Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Registered]);
    $candidateTwo = Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Registered]);
    $candidateThree = Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Registered]);

    // A group transaction worth $50.00 total, but only $25.00 of it has
    // actually been allocated to candidates so far — Paid must reflect the
    // $25.00 of real allocations, never the parent transaction's $50.00.
    $transaction = PaymentTransaction::factory()->create([
        'version_id' => $version->id,
        'amount' => 5000,
        'status' => PaymentTransactionStatus::Completed,
        'fee_type' => FeeType::Registration,
    ]);

    PaymentAllocation::factory()->create([
        'payment_transaction_id' => $transaction->id,
        'candidate_id' => $candidateOne->id,
        'amount' => 1000,
    ]);
    PaymentAllocation::factory()->create([
        'payment_transaction_id' => $transaction->id,
        'candidate_id' => $candidateTwo->id,
        'amount' => 1500,
    ]);

    // A Participation-fee payment must not count toward Registration Fees Paid.
    $participationTransaction = PaymentTransaction::factory()->create([
        'version_id' => $version->id,
        'amount' => 3000,
        'status' => PaymentTransactionStatus::Completed,
        'fee_type' => FeeType::Participation,
    ]);
    PaymentAllocation::factory()->create([
        'payment_transaction_id' => $participationTransaction->id,
        'candidate_id' => $candidateThree->id,
        'amount' => 3000,
    ]);

    $metrics = app(VersionScorecardService::class)->metricsFor($version);

    expect($metrics->registrationFeesDueCents)->toBe(6000)
        ->and($metrics->registrationFeesPaidCents)->toBe(2500)
        ->and($metrics->registrationFeesOutstandingCents)->toBe(3500);
});
