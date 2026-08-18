<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\FeeType;
use App\Enums\ObligationDecision;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\Vendor;
use App\Enums\VersionObligationStatus;
use App\Livewire\Registrations\VersionDashboard;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\EventEpaymentConfig;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEpaymentConfig;
use App\Models\VersionFee;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use App\Models\VersionObligationResponse;
use App\Models\VersionTeacherEpaymentOptIn;
use App\Models\VoicePart;
use App\Services\EligibilityService;
use App\Services\Payments\Dto\CheckoutSession;
use App\Services\Payments\Dto\WebhookEvent;
use App\Services\Payments\PaymentGatewayContract;
use App\Services\Payments\SquarePaymentGateway;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * Stands in for the real SquarePaymentGateway in tests that exercise
 * VersionDashboard's own responsibility (gathering/authorizing candidates,
 * calling the gateway, redirecting) without hitting Square's real sandbox —
 * SquarePaymentGateway::createCheckoutSession() itself is already verified
 * against the real sandbox (epayment-integration.md §4 step 4).
 */
class FakeSquareGateway implements PaymentGatewayContract
{
    /** @var Collection<int, Candidate>|null */
    public static ?Collection $lastCandidates = null;

    public static ?FeeType $lastFeeType = null;

    /**
     * @param  Collection<int, Candidate>  $candidates
     */
    public function createCheckoutSession(Version $version, Collection $candidates, Teacher $payer, FeeType $feeType): CheckoutSession
    {
        self::$lastCandidates = $candidates;
        self::$lastFeeType = $feeType;

        $transaction = PaymentTransaction::create([
            'version_id' => $version->id,
            'source' => $candidates->count() === 1 ? PaymentSource::CandidateEpayment : PaymentSource::TeacherEpayment,
            'vendor' => Vendor::Square,
            'vendor_transaction_id' => 'fake-order-'.uniqid(),
            'payer_teacher_id' => $payer->id,
            'school_id' => $candidates->first()?->school_id,
            'amount' => 12345,
            'status' => PaymentTransactionStatus::Pending,
            'fee_type' => $feeType,
        ]);

        return new CheckoutSession(redirectUrl: 'https://fake.example/checkout', paymentTransactionId: $transaction->id);
    }

    public function verifyWebhookSignature(Request $request, Event $event): bool
    {
        return true;
    }

    public function parseWebhookEvent(Request $request): WebhookEvent
    {
        throw new RuntimeException('not used in these tests');
    }
}

function makeReadyForGroupPayment(Version $version): void
{
    VersionEpaymentConfig::create(['version_id' => $version->id, 'epayment_student' => false, 'epayment_teacher' => true]);
    EventEpaymentConfig::create([
        'event_id' => $version->event_id,
        'vendor' => Vendor::Square,
        'vendor_account_id' => 'loc-123',
        'secret' => 'token-123',
    ]);

    app()->bind(SquarePaymentGateway::class, fn () => new FakeSquareGateway);
}

function makeRegistrationTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
}

function attachEligibleStudentToTeacher(Teacher $teacher, School $school): Student
{
    $student = Student::factory()->create();

    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    $student->schools()->attach($school->id, ['is_active' => true, 'class_of' => (int) date('Y') + 1]);
    $student->teachers()->attach($teacher->id, [
        'school_id' => $school->id,
        'subject' => 'chorus',
        'role' => 'primary',
        'is_active' => true,
    ]);

    return $student;
}

function inviteRegistrationTeacher(Teacher $teacher, Version $version, string $status = 'invited'): VersionInvitation
{
    return VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => $status,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

function publishObligationForRegistrationTeacher(Version $version): VersionObligation
{
    return VersionObligation::create([
        'version_id' => $version->id,
        'body' => '<p>Be excellent.</p>',
        'status' => VersionObligationStatus::Published->value,
        'published_at' => now(),
        'published_by_user_id' => User::factory()->create()->id,
    ]);
}

test('mount displays the version name', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['name' => 'Fall Auditions']);
    inviteRegistrationTeacher($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('Fall Auditions');
});

test('the Take a tour button auto-starts for a teacher who has never taken it', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('Take a tour')
        ->assertSeeHtml('data-auto-start="1"');
});

test('the Take a tour button does not auto-start once the tour has already been taken', function () {
    $teacher = makeRegistrationTeacher();
    $teacher->user->update(['dismissed_registration_orientation_at' => now()]);
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('Take a tour')
        ->assertSeeHtml('data-auto-start="0"');
});

test('dismissOrientation persists the dismissal and stops the tour from auto-starting on a re-render', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSeeHtml('data-auto-start="1"')
        ->call('dismissOrientation')
        ->assertSeeHtml('data-auto-start="0"');

    expect($teacher->user->fresh()->dismissed_registration_orientation_at)->not->toBeNull();
});

test('dismissing the tour on one Version also stops it auto-starting on another', function () {
    $teacher = makeRegistrationTeacher();
    $versionA = Version::factory()->create();
    $versionB = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $versionA);
    inviteRegistrationTeacher($teacher, $versionB);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $versionA])
        ->call('dismissOrientation');

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $versionB])
        ->assertSeeHtml('data-auto-start="0"');
});

test('mount redirects an eligible but uninvited teacher to the Request Invitation page', function () {
    $teacher = makeRegistrationTeacher();
    $school = School::factory()->create();
    $teacher->schools()->attach($school->id, ['is_active' => true, 'verified_at' => now()]);
    $version = Version::factory()->create();

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertRedirect(route('registrations.request-invitation', $version));
});

test('mount aborts with 403 for an ineligible, uninvited teacher', function () {
    $teacher = makeRegistrationTeacher();
    // No active+verified school attached — fails the base eligibility gate.
    $version = Version::factory()->create();

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount redirects an invited teacher who has not yet responded to a published obligation', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);
    publishObligationForRegistrationTeacher($version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertRedirect(route('registrations.obligations', $version));
});

test('mount does not redirect when the Version has no published obligation', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['name' => 'No Obligation Version']);
    inviteRegistrationTeacher($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('No Obligation Version');
});

test('mount does not redirect a teacher who already accepted the obligation', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['name' => 'Accepted Version']);
    $invitation = inviteRegistrationTeacher($teacher, $version, 'obligated');
    $obligation = publishObligationForRegistrationTeacher($version);

    VersionObligationResponse::create([
        'version_invitation_id' => $invitation->id,
        'version_obligation_id' => $obligation->id,
        'decision' => ObligationDecision::Accepted->value,
        'decided_at' => now(),
        'obligation_snapshot' => $obligation->body,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('Accepted Version');
});

test('mount redirects a teacher who already rejected the obligation back to the obligations form, same as never having responded', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['name' => 'Rejected Version']);
    $invitation = inviteRegistrationTeacher($teacher, $version, 'rejected');
    $obligation = publishObligationForRegistrationTeacher($version);

    VersionObligationResponse::create([
        'version_invitation_id' => $invitation->id,
        'version_obligation_id' => $obligation->id,
        'decision' => ObligationDecision::Rejected->value,
        'decided_at' => now(),
        'obligation_snapshot' => $obligation->body,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertRedirect(route('registrations.obligations', $version));
});

test('eligibleStudents (and its isNotInvited gate) is blocked for an uninvited teacher, even bypassing the page-level gate', function () {
    $teacher = makeRegistrationTeacher();
    $school = School::factory()->create();
    $student = attachEligibleStudentToTeacher($teacher, $school);
    $version = Version::factory()->create();

    // EligibilityService::eligibleStudents() is the defense-in-depth layer
    // behind VersionDashboard::mount()'s gate — assert it independently
    // returns nothing for an uninvited teacher, regardless of the page gate.
    expect(app(EligibilityService::class)->eligibleStudents($version, $teacher))->toBeEmpty();
    expect(app(EligibilityService::class)->isNotInvited($version, $teacher))->toBeTrue();
});

test('withdraw sets the candidate status to teacher_withdrawn', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Registered,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('withdraw', $candidate->id);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::TeacherWithdrawn);
});

test('withdraw cannot target a candidate belonging to another teacher', function () {
    $teacher = makeRegistrationTeacher();
    $otherTeacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($otherTeacher->user);
    inviteRegistrationTeacher($teacher, $version);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $otherTeacher->id,
        'status' => CandidateStatus::Registered,
    ]);

    expect(function () use ($teacher, $version, $candidate) {
        Livewire::actingAs($teacher->user)
            ->test(VersionDashboard::class, ['version' => $version])
            ->call('withdraw', $candidate->id);
    })->toThrow(ModelNotFoundException::class);

    expect($candidate->refresh()->status)->toBe(CandidateStatus::Registered);
});

test('My Candidates is sorted by the student\'s alpha name order, not program_name', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    // Deliberately opposite: program_name order would put "Aardvark" first
    // and "Zebra" last, but sort_name order (Adams before Zeta) is the
    // reverse — proves the sort key is the student's name, not program_name.
    $adams = Student::factory()->create();
    $adams->user->update(['first_name' => 'Aaron', 'last_name' => 'Adams']);
    $zeta = Student::factory()->create();
    $zeta->user->update(['first_name' => 'Zoe', 'last_name' => 'Zeta']);

    actingAs($teacher->user);

    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $zeta->id, 'program_name' => 'Aardvark Program']);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $adams->id, 'program_name' => 'Zebra Program']);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSeeInOrder(['Adams, Aaron', 'Zeta, Zoe']);
});

test('the Paid column shows the sum of Completed payment_allocations for that candidate, net of refunds, and 0 for a candidate with no payments', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);

    $paid = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $unpaid = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    $check = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payer_teacher_id' => $teacher->id,
        'amount' => 2000, 'status' => PaymentTransactionStatus::Completed, 'payment_type' => 'check', 'paid_at' => now(),
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $check->id, 'candidate_id' => $paid->id, 'amount' => 2000, 'allocated_at' => now()]);

    $refund = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payer_teacher_id' => $teacher->id,
        'amount' => -500, 'status' => PaymentTransactionStatus::Completed, 'payment_type' => 'refund', 'paid_at' => now(),
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $refund->id, 'candidate_id' => $paid->id, 'amount' => -500, 'allocated_at' => now()]);

    // Pending, not Completed — must not count toward Paid.
    $pending = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payer_teacher_id' => $teacher->id,
        'amount' => 9999, 'status' => PaymentTransactionStatus::Pending, 'payment_type' => 'check',
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $pending->id, 'candidate_id' => $paid->id, 'amount' => 9999, 'allocated_at' => now()]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('$15.00')
        ->assertSee('$0.00');
});

test('the voice part summary table counts only Registered candidates per voice part, with a Registered total column', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    // Attaches voice parts to the Version's Event via an Ensemble, so they
    // show up in Version::availableVoiceParts() — the "eligible ensemble
    // voice parts" the count table is scoped to, not every VoicePart in
    // the system.
    $ensemble = Ensemble::factory()->create(['event_id' => $version->event_id]);
    $soprano = VoicePart::factory()->create(['name' => 'Soprano', 'abbr' => 'SOP', 'sort_order' => 1]);
    $alto = VoicePart::factory()->create(['name' => 'Alto', 'abbr' => 'ALT', 'sort_order' => 2]);
    $ensemble->voiceParts()->attach([$soprano->id, $alto->id]);

    actingAs($teacher->user);

    // Soprano: 1 eligible (not counted) + 1 registered (counted) = 1.
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'voice_part_id' => $soprano->id, 'status' => CandidateStatus::Eligible]);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'voice_part_id' => $soprano->id, 'status' => CandidateStatus::Registered]);
    // Alto: 1 pending (not counted) = 0.
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'voice_part_id' => $alto->id, 'status' => CandidateStatus::Pending]);

    $component = Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version]);

    // Column headers (abbr values SOP, ALT, Registered) come before the
    // single row of values (1, 0, 1) in DOM order. The voice part table
    // uses abbr, not the full name (the full names still legitimately
    // appear elsewhere, in the "All voice parts" filter/enroll dropdowns,
    // so this doesn't assertDontSee them).
    $component->assertSeeInOrder(['SOP', 'ALT', 'Registered', '1', '0', '1']);
    $component->assertSeeInOrder(['Eligible', 'Pending', 'Registered', 'Total', '1', '1', '1', '3']);
});

test('search filters candidates by the linked student user\'s name', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    $wendel = Student::factory()->create();
    $wendel->user->update(['first_name' => 'Wendel', 'last_name' => 'Quoxbury']);
    $zoe = Student::factory()->create();
    $zoe->user->update(['first_name' => 'Zoe', 'last_name' => 'Adams']);

    // CandidateObserver::created() writes a candidate_status_history row
    // with user_id = Auth::id(), which is NOT NULL — needs an authenticated
    // user in place before the insert, not just at Livewire::actingAs() below.
    actingAs($teacher->user);

    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $wendel->id, 'program_name' => 'Wendel Quoxbury']);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $zoe->id, 'program_name' => 'Zoe Adams']);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('search', 'wendel')
        ->assertSee('Quoxbury, Wendel')
        ->assertDontSee('Adams, Zoe');
});

test('voicePartFilter shows only candidates with the selected voice part', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    $soprano = VoicePart::factory()->create(['name' => 'Soprano']);
    $alto = VoicePart::factory()->create(['name' => 'Alto']);

    $sopranoStudent = Student::factory()->create();
    $sopranoStudent->user->update(['first_name' => 'Sally', 'last_name' => 'Soprano']);
    $altoStudent = Student::factory()->create();
    $altoStudent->user->update(['first_name' => 'Alan', 'last_name' => 'Alto']);

    actingAs($teacher->user);

    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $sopranoStudent->id, 'voice_part_id' => $soprano->id]);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $altoStudent->id, 'voice_part_id' => $alto->id]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('voicePartFilter', (string) $alto->id)
        ->assertSee('Alto, Alan')
        ->assertDontSee('Soprano, Sally');
});

test('statusFilter shows only candidates with the selected status', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    $eligibleStudent = Student::factory()->create();
    $eligibleStudent->user->update(['first_name' => 'Ellie', 'last_name' => 'Eligible']);
    $registeredStudent = Student::factory()->create();
    $registeredStudent->user->update(['first_name' => 'Rita', 'last_name' => 'Registered']);

    actingAs($teacher->user);

    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $eligibleStudent->id, 'status' => CandidateStatus::Eligible]);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id, 'student_id' => $registeredStudent->id, 'status' => CandidateStatus::Registered]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('statusFilter', CandidateStatus::Registered->value)
        ->assertSee('Registered, Rita')
        ->assertDontSee('Eligible, Ellie');
});

test('search, voicePartFilter, and statusFilter combine, and an empty result shows the no-match message', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    $soprano = VoicePart::factory()->create(['name' => 'Soprano']);
    $wendel = Student::factory()->create();
    $wendel->user->update(['first_name' => 'Wendel', 'last_name' => 'Quoxbury']);

    actingAs($teacher->user);

    Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'student_id' => $wendel->id,
        'voice_part_id' => $soprano->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('search', 'wendel')
        ->set('voicePartFilter', (string) $soprano->id)
        ->set('statusFilter', CandidateStatus::Eligible->value)
        ->assertSee('Quoxbury, Wendel')
        ->set('statusFilter', CandidateStatus::Registered->value)
        ->assertDontSee('Quoxbury, Wendel')
        ->assertSee('No candidates match your search/filters.');
});

test('toggleEpaymentOptIn flips the teacher+Version opt-in and is scoped per teacher', function () {
    $teacher = makeRegistrationTeacher();
    $otherTeacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    VersionEpaymentConfig::create(['version_id' => $version->id, 'epayment_student' => true, 'epayment_teacher' => false]);

    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('toggleEpaymentOptIn');

    $optIn = VersionTeacherEpaymentOptIn::where('version_id', $version->id)->where('teacher_id', $teacher->id)->first();
    expect($optIn->opted_in)->toBeTrue();

    // The other teacher's own state is untouched.
    expect(VersionTeacherEpaymentOptIn::where('version_id', $version->id)->where('teacher_id', $otherTeacher->id)->exists())->toBeFalse();
});

test('payForSelected aborts with 403 when epayment_teacher is not ready', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('selectedCandidateIds', [$candidate->id])
        ->call('payForSelected', 'registration')
        ->assertStatus(403);
});

test('payForSelected aborts with 422 when nothing is selected', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    makeReadyForGroupPayment($version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('payForSelected', 'registration')
        ->assertStatus(422);
});

test('payForSelected aborts with 403 when participation is requested before the Version is closed', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    makeReadyForGroupPayment($version);

    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Accepted,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('selectedCandidateIds', [$candidate->id])
        ->call('payForSelected', 'participation')
        ->assertStatus(403);
});

test('payForSelected aborts with 422 when none of the selected candidates are Accepted for participation', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['status' => EventStatus::Closed]);
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    makeReadyForGroupPayment($version);

    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::NotAccepted,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('selectedCandidateIds', [$candidate->id])
        ->call('payForSelected', 'participation')
        ->assertStatus(422);
});

test('payForSelected redirects to the gateway checkout URL, scoped to only this teacher\'s own selected candidates', function () {
    $teacher = makeRegistrationTeacher();
    $otherTeacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    makeReadyForGroupPayment($version);

    $mine1 = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $mine2 = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $notMine = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $otherTeacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('selectedCandidateIds', [$mine1->id, $mine2->id, $notMine->id])
        ->call('payForSelected', 'registration')
        ->assertRedirect('https://fake.example/checkout');

    expect(FakeSquareGateway::$lastCandidates->pluck('id')->sort()->values()->all())
        ->toBe(collect([$mine1->id, $mine2->id])->sort()->values()->all());
    expect(FakeSquareGateway::$lastFeeType)->toBe(FeeType::Registration);

    $transaction = PaymentTransaction::where('version_id', $version->id)->first();
    expect($transaction->getRawOriginal('source'))->toBe('teacher_epayment');
    expect($transaction->getRawOriginal('fee_type'))->toBe('registration');
    expect($transaction->allocations)->toHaveCount(0);
});

test('Your Unreconciled Payments shows only this teacher\'s own transactions with a remaining balance', function () {
    $teacher = makeRegistrationTeacher();
    $otherTeacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);

    $mine = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    $unreconciled = PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $teacher->id,
        'school_id' => $mine->school_id,
        'amount' => 5000,
        'status' => PaymentTransactionStatus::Completed,
        'reference_number' => 'GROUP-1',
    ]);

    $fullyAllocated = PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $teacher->id,
        'school_id' => $mine->school_id,
        'amount' => 3000,
        'status' => PaymentTransactionStatus::Completed,
        'reference_number' => 'GROUP-2',
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $fullyAllocated->id, 'candidate_id' => $mine->id, 'amount' => 3000, 'allocated_at' => now()]);

    PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $otherTeacher->id,
        'school_id' => $mine->school_id,
        'amount' => 9000,
        'status' => PaymentTransactionStatus::Completed,
        'reference_number' => 'GROUP-OTHER',
    ]);

    // Not assertDontSee('GROUP-2') for the "Your Unreconciled Payments"
    // check below — GROUP-2 is fully allocated to $mine, so it now
    // legitimately appears in the (separate) Payment Register section,
    // which lists every payment regardless of reconciliation state. Scoping
    // to the openAllocate(...) wire:click instead targets only the
    // Unreconciled section's own "Allocate" button.
    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('GROUP-1')
        ->assertDontSee('GROUP-OTHER')
        ->assertSeeHtml("wire:click=\"openAllocate({$unreconciled->id})\"")
        ->assertDontSeeHtml("wire:click=\"openAllocate({$fullyAllocated->id})\"");
});

test('openAllocate aborts with 404 for a transaction not owned by this teacher', function () {
    $teacher = makeRegistrationTeacher();
    $otherTeacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $otherTeacher->id,
        'amount' => 5000,
        'status' => PaymentTransactionStatus::Completed,
    ]);

    expect(function () use ($teacher, $version, $transaction) {
        Livewire::actingAs($teacher->user)
            ->test(VersionDashboard::class, ['version' => $version])
            ->call('openAllocate', $transaction->id);
    })->toThrow(ModelNotFoundException::class);
});

test('saveAllocations creates allocation rows for this teacher\'s own candidates and reduces the remaining balance', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);

    $candidateA = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $candidateB = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $teacher->id,
        'amount' => 5000,
        'status' => PaymentTransactionStatus::Completed,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('openAllocate', $transaction->id)
        ->set("allocationAmounts.{$candidateA->id}", '20.00')
        ->set("allocationAmounts.{$candidateB->id}", '15.00')
        ->call('saveAllocations')
        ->assertHasNoErrors();

    expect($transaction->refresh()->unallocatedAmount())->toBe(1500);
    expect(PaymentAllocation::where('payment_transaction_id', $transaction->id)->where('candidate_id', $candidateA->id)->value('amount'))->toBe(2000);
    expect(PaymentAllocation::where('payment_transaction_id', $transaction->id)->where('candidate_id', $candidateB->id)->value('amount'))->toBe(1500);
});

test('saveAllocations rejects allocating more than the remaining balance', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);

    $candidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $teacher->id,
        'amount' => 5000,
        'status' => PaymentTransactionStatus::Completed,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('openAllocate', $transaction->id)
        ->set("allocationAmounts.{$candidate->id}", '999.00')
        ->call('saveAllocations')
        ->assertStatus(422);

    expect($transaction->refresh()->allocations)->toHaveCount(0);
});

test('saveAllocations rejects a candidate outside this teacher\'s own roster', function () {
    $teacher = makeRegistrationTeacher();
    $otherTeacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);

    $notMyCandidate = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $otherTeacher->id]);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $teacher->id,
        'amount' => 5000,
        'status' => PaymentTransactionStatus::Completed,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('openAllocate', $transaction->id)
        ->set("allocationAmounts.{$notMyCandidate->id}", '10.00')
        ->call('saveAllocations')
        ->assertStatus(403);
});

test('refreshStatus recalculates the candidate status from the checklist', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['emergency_contact_name' => true]);
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Eligible,
        'program_name' => 'A Candidate',
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('refreshStatus', $candidate->id);

    // program_name is done but emergency contact is not, so the candidate
    // should move from eligible to pending — not stay eligible.
    expect($candidate->refresh()->status)->toBe(CandidateStatus::Pending);
});

test('the action toolbar shows Payment Register, Estimate Form, and Group Payment', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('Payment Register')
        ->assertSee('Estimate Form')
        ->assertSee('Group Payment');
});

test('Group Payment explains itself instead of offering selection when no fee window is open', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->assertSee('Group Payment is not available right now');
});

test('Group Payment offers separate Pay Participation Fee and Pay Housing Fee buttons at once, once the Version is closed', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['status' => EventStatus::Closed]);
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    makeReadyForGroupPayment($version);
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000, 'participation' => 500, 'housing' => 1500]);

    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Accepted,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('selectedCandidateIds', [$candidate->id])
        ->assertDontSee('Pay Registration Fee for Selected')
        ->assertSee('Pay Participation Fee for Selected')
        ->assertSee('Pay Housing Fee for Selected');
});

test('payForSelected aborts with 403 for housing before the Version is closed', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    makeReadyForGroupPayment($version);

    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Accepted,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('selectedCandidateIds', [$candidate->id])
        ->call('payForSelected', 'housing')
        ->assertStatus(403);
});

test('payForSelected aborts with 422 when none of the selected candidates are Accepted for housing', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create(['status' => EventStatus::Closed]);
    actingAs($teacher->user);
    inviteRegistrationTeacher($teacher, $version);
    makeReadyForGroupPayment($version);

    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::NotAccepted,
    ]);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->set('selectedCandidateIds', [$candidate->id])
        ->call('payForSelected', 'housing')
        ->assertStatus(422);
});

test('openPaymentRegister does not error', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    inviteRegistrationTeacher($teacher, $version);

    Livewire::actingAs($teacher->user)
        ->test(VersionDashboard::class, ['version' => $version])
        ->call('openPaymentRegister')
        ->assertStatus(200);
});

test('paymentRegisterRows lists every allocation across this teacher\'s roster, ordered by candidate sort name then payment chronology, excluding other teachers\' candidates', function () {
    $teacher = makeRegistrationTeacher();
    $otherTeacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);

    $zeta = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $zeta->student->user->update(['first_name' => 'Zoe', 'last_name' => 'Zeta']);
    $adams = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $adams->student->user->update(['first_name' => 'Amy', 'last_name' => 'Adams']);
    $notMine = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $otherTeacher->id]);

    $zetaCheck = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payer_teacher_id' => $teacher->id,
        'amount' => 2000, 'status' => PaymentTransactionStatus::Completed, 'payment_type' => 'check',
        'reference_number' => 'REF-1', 'paid_at' => now()->subDays(2),
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $zetaCheck->id, 'candidate_id' => $zeta->id, 'amount' => 2000, 'allocated_at' => now()]);

    $adamsFirst = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payer_teacher_id' => $teacher->id,
        'amount' => 1000, 'status' => PaymentTransactionStatus::Completed, 'payment_type' => 'cash',
        'paid_at' => now()->subDays(5),
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $adamsFirst->id, 'candidate_id' => $adams->id, 'amount' => 1000, 'allocated_at' => now()]);

    $adamsSecond = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payer_teacher_id' => $teacher->id,
        'amount' => -500, 'status' => PaymentTransactionStatus::Completed, 'payment_type' => 'refund',
        'paid_at' => now()->subDay(),
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $adamsSecond->id, 'candidate_id' => $adams->id, 'amount' => -500, 'allocated_at' => now()]);

    $notMinePayment = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payer_teacher_id' => $otherTeacher->id,
        'amount' => 9999, 'status' => PaymentTransactionStatus::Completed, 'payment_type' => 'check', 'paid_at' => now(),
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $notMinePayment->id, 'candidate_id' => $notMine->id, 'amount' => 9999, 'allocated_at' => now()]);

    $rows = VersionDashboard::paymentRegisterRows($version, $teacher);

    expect($rows)->toHaveCount(3);
    // Adams before Zeta (sort_name order), and within Adams, oldest payment first.
    expect($rows[0]['candidate']->id)->toBe($adams->id);
    expect($rows[0]['amountCents'])->toBe(1000);
    expect($rows[1]['candidate']->id)->toBe($adams->id);
    expect($rows[1]['amountCents'])->toBe(-500);
    expect($rows[1]['type'])->toBe('Refund');
    expect($rows[2]['candidate']->id)->toBe($zeta->id);
    expect($rows[2]['referenceNumber'])->toBe('REF-1');
    expect($rows[2]['status'])->toBe(PaymentTransactionStatus::Completed);
});

test('the Payment Register CSV export is scoped to this teacher\'s own candidates', function () {
    $teacher = makeRegistrationTeacher();
    $otherTeacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    $teacher->schools()->attach(School::factory()->create()->id, ['is_active' => true, 'verified_at' => now()]);

    $mine = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);
    $notMine = Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $otherTeacher->id]);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payer_teacher_id' => $teacher->id,
        'amount' => 2000, 'status' => PaymentTransactionStatus::Completed, 'payment_type' => 'check', 'paid_at' => now(),
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $mine->id, 'amount' => 2000, 'allocated_at' => now()]);

    $otherTransaction = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payer_teacher_id' => $otherTeacher->id,
        'amount' => 5000, 'status' => PaymentTransactionStatus::Completed, 'payment_type' => 'check', 'paid_at' => now(),
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $otherTransaction->id, 'candidate_id' => $notMine->id, 'amount' => 5000, 'allocated_at' => now()]);

    $response = get(route('registrations.payment-register-csv', $version));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain($mine->student->user->sort_name);
    expect($content)->not->toContain($notMine->student->user->sort_name);
});

test('the Payment Register PDF export is scoped to this teacher\'s own candidates', function () {
    $teacher = makeRegistrationTeacher();
    $version = Version::factory()->create();
    actingAs($teacher->user);
    $teacher->schools()->attach(School::factory()->create()->id, ['is_active' => true, 'verified_at' => now()]);

    Candidate::factory()->create(['version_id' => $version->id, 'teacher_id' => $teacher->id]);

    $response = get(route('registrations.payment-register-pdf', $version));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf');
});
