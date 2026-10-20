<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Livewire\Events\Reports\PaymentReconciliation;
use App\Models\Candidate;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionFee;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * @return array{school: School, teacher: Teacher, candidate: Candidate}
 */
function makeReconciliationCandidate(Version $version, ?County $county = null): array
{
    $county ??= County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    $teacher = Teacher::factory()->create();
    $candidate = Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    return ['school' => $school, 'teacher' => $teacher, 'candidate' => $candidate];
}

function makeUnallocatedGroupPayment(Version $version, School $school, Teacher $teacher, int $amount): PaymentTransaction
{
    return PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'amount' => $amount,
        'status' => PaymentTransactionStatus::Completed,
        'reference_number' => 'GROUP-1',
    ]);
}

test('mount aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertStatus(403);
});

test('school balances reflect completed allocations, not the raw transaction total', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000, 'participation' => 500]);
    ['school' => $school, 'candidate' => $candidate] = makeReconciliationCandidate($version);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::Manual,
        'school_id' => $school->id,
        'amount' => 1500,
        'status' => PaymentTransactionStatus::Completed,
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $candidate->id, 'amount' => 1500, 'allocated_at' => now()]);

    // Registration and participation are never combined — a still-Registered
    // candidate on an open Version only owes registration; participation
    // isn't assessed until the Version closes and the candidate is Accepted
    // (see the "participation is only added once closed/Accepted" test below).
    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertSee($school->name)
        ->assertSee('20.00') // due: registration only
        ->assertSee('15.00'); // paid
});

test('participation fee is only added to due once the Version is closed and the candidate is Accepted', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create(['status' => EventStatus::Closed]);
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000, 'participation' => 500]);

    $county = County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    $teacher = Teacher::factory()->create();
    Candidate::factory()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'status' => CandidateStatus::Accepted,
    ]);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertSee($school->name)
        ->assertSee('25.00'); // due: registration $20 + participation $5
});

test('Eligible and Pending candidates are not counted as registered and owe nothing, but their payments still count as paid', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000, 'participation' => 500]);
    ['school' => $school, 'teacher' => $teacher, 'candidate' => $registered] = makeReconciliationCandidate($version);

    $attributes = ['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id];
    $eligible = Candidate::factory()->create([...$attributes, 'status' => CandidateStatus::Eligible]);
    Candidate::factory()->create([...$attributes, 'status' => CandidateStatus::Pending]);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'school_id' => $school->id,
        'amount' => 2000, 'status' => PaymentTransactionStatus::Completed,
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $eligible->id, 'amount' => 2000, 'allocated_at' => now()]);

    $row = PaymentReconciliation::baseRows($version, null)->first();

    expect($row['count'])->toBe(1)
        ->and($row['dueCents'])->toBe(2000)
        ->and($row['paidCents'])->toBe(2000)
        ->and($row['balanceCents'])->toBe(0);
});

test('a school with only unregistered candidates is listed only once something has been paid for them', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000]);
    $unpaidSchool = School::factory()->create();
    $paidSchool = School::factory()->create();
    $teacher = Teacher::factory()->create();

    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $unpaidSchool->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Eligible]);
    $prepaid = Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $paidSchool->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Pending]);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'school_id' => $paidSchool->id,
        'amount' => 2000, 'status' => PaymentTransactionStatus::Completed,
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $prepaid->id, 'amount' => 2000, 'allocated_at' => now()]);

    $rows = PaymentReconciliation::baseRows($version, null);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['school']->id)->toBe($paidSchool->id)
        ->and($rows->first()['count'])->toBe(0)
        ->and($rows->first()['balanceCents'])->toBe(-2000)
        ->and($rows->first()['teachers']->pluck('id')->all())->toBe([$teacher->id]);
});

test('each school row lists its teachers with email and mobile phone', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['teacher' => $teacher] = makeReconciliationCandidate($version);
    $teacher->user->update(['cell_phone' => '6095550123']);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertSee($teacher->user->name)
        ->assertSee($teacher->user->email)
        ->assertSee('(609) 555-0123');
});

test('a fully allocated transaction is absent from Needs Reconciliation, an unallocated one is present', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher, 'candidate' => $candidate] = makeReconciliationCandidate($version);

    $fullyAllocated = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'school_id' => $school->id,
        'amount' => 1000, 'status' => PaymentTransactionStatus::Completed, 'reference_number' => 'DONE-1',
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $fullyAllocated->id, 'candidate_id' => $candidate->id, 'amount' => 1000, 'allocated_at' => now()]);

    makeUnallocatedGroupPayment($version, $school, $teacher, 5000);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertSee('GROUP-1')
        ->assertDontSee('DONE-1');
});

test('a Co-Registration Manager only sees school balances and unreconciled payments within their assigned county', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    ['school' => $schoolA, 'teacher' => $teacherA] = makeReconciliationCandidate($version, $countyA);
    ['school' => $schoolB, 'teacher' => $teacherB] = makeReconciliationCandidate($version, $countyB);

    makeUnallocatedGroupPayment($version, $schoolA, $teacherA, 1000);
    makeUnallocatedGroupPayment($version, $schoolB, $teacherB, 2000);

    $coRegManager = User::factory()->create();
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $coRegManager->id, 'county_id' => $countyA->id]);

    Livewire::actingAs($coRegManager)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertSee($schoolA->name)
        ->assertDontSee($schoolB->name);
});

test('openAllocate aborts with 404 for a transaction outside the Version', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $otherVersion = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher] = makeReconciliationCandidate($otherVersion);
    $transaction = makeUnallocatedGroupPayment($otherVersion, $school, $teacher, 1000);

    expect(function () use ($founder, $version, $transaction) {
        Livewire::actingAs($founder)
            ->test(PaymentReconciliation::class, ['version' => $version])
            ->call('openAllocate', $transaction->id);
    })->toThrow(ModelNotFoundException::class);
});

test('openAllocate aborts with 403 for a transaction outside the acting Co-Registration Manager\'s county', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    ['school' => $schoolB, 'teacher' => $teacherB] = makeReconciliationCandidate($version, $countyB);
    $transaction = makeUnallocatedGroupPayment($version, $schoolB, $teacherB, 1000);

    $coRegManager = User::factory()->create();
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $coRegManager->id, 'county_id' => $countyA->id]);

    Livewire::actingAs($coRegManager)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->call('openAllocate', $transaction->id)
        ->assertStatus(403);
});

test('saveAllocations is unscoped — allocates to any candidate in the Version, not just one teacher\'s roster', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $schoolA, 'teacher' => $teacherA, 'candidate' => $candidateA] = makeReconciliationCandidate($version);
    ['candidate' => $candidateB] = makeReconciliationCandidate($version);

    $transaction = makeUnallocatedGroupPayment($version, $schoolA, $teacherA, 5000);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->call('openAllocate', $transaction->id)
        ->set("allocationAmounts.{$candidateA->id}", '20.00')
        ->set("allocationAmounts.{$candidateB->id}", '30.00')
        ->call('saveAllocations')
        ->assertHasNoErrors();

    expect($transaction->refresh()->unallocatedAmount())->toBe(0);
    expect(PaymentAllocation::where('payment_transaction_id', $transaction->id)->count())->toBe(2);
});

test('saveAllocations rejects a candidate outside the Version', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $otherVersion = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher] = makeReconciliationCandidate($version);
    ['candidate' => $foreignCandidate] = makeReconciliationCandidate($otherVersion);

    $transaction = makeUnallocatedGroupPayment($version, $school, $teacher, 5000);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->call('openAllocate', $transaction->id)
        ->set("allocationAmounts.{$foreignCandidate->id}", '20.00')
        ->call('saveAllocations')
        ->assertStatus(403);
});

test('saveAllocations rejects allocating more than the remaining balance', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher, 'candidate' => $candidate] = makeReconciliationCandidate($version);

    $transaction = makeUnallocatedGroupPayment($version, $school, $teacher, 1000);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->call('openAllocate', $transaction->id)
        ->set("allocationAmounts.{$candidate->id}", '999.00')
        ->call('saveAllocations')
        ->assertStatus(422);

    expect($transaction->refresh()->allocations)->toHaveCount(0);
});

test('search narrows the school balance table by school name', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $wantedSchool] = makeReconciliationCandidate($version);
    ['school' => $otherSchool] = makeReconciliationCandidate($version);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->set('search', $wantedSchool->name)
        ->assertSee($wantedSchool->name)
        ->assertDontSee($otherSchool->name);
});

test('PDF export returns a PDF for an authorized user', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();

    get(route('events.versions.reports.payment-reconciliation.export', ['version' => $version, 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('PDF export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.payment-reconciliation.export', ['version' => $version, 'format' => 'pdf']))
        ->assertForbidden();
});

test('the Needs Reconciliation queue lists completed transactions only', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher] = makeReconciliationCandidate($version);

    $completed = makeUnallocatedGroupPayment($version, $school, $teacher, 5000);

    $unsettled = collect([
        PaymentTransactionStatus::Pending,
        PaymentTransactionStatus::Failed,
        PaymentTransactionStatus::Refunded,
    ])->map(fn (PaymentTransactionStatus $status): PaymentTransaction => PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'amount' => 5000,
        'status' => $status,
    ]));

    $component = Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertSeeHtml("wire:click=\"openAllocate({$completed->id})\"");

    foreach ($unsettled as $transaction) {
        $component->assertDontSeeHtml("wire:click=\"openAllocate({$transaction->id})\"");
    }
});

test('openAllocate rejects an unsettled transaction', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher] = makeReconciliationCandidate($version);

    $pending = PaymentTransaction::create([
        'version_id' => $version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $teacher->id,
        'school_id' => $school->id,
        'amount' => 5000,
        'status' => PaymentTransactionStatus::Pending,
    ]);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->call('openAllocate', $pending->id)
        ->assertStatus(422);
});

test('the Details button appears only on school rows with a non-zero Paid value', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $paidSchool, 'candidate' => $candidate] = makeReconciliationCandidate($version);
    ['school' => $unpaidSchool] = makeReconciliationCandidate($version);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'school_id' => $paidSchool->id,
        'amount' => 2000, 'status' => PaymentTransactionStatus::Completed,
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $candidate->id, 'amount' => 2000, 'allocated_at' => now()]);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertSeeHtml("wire:click=\"showPaymentDetails({$paidSchool->id})\"")
        ->assertDontSeeHtml("wire:click=\"showPaymentDetails({$unpaidSchool->id})\"");
});

test('payment details list each payer and only the portion of a group payment credited to that school', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $schoolA, 'teacher' => $teacherA, 'candidate' => $candidateA] = makeReconciliationCandidate($version);
    ['candidate' => $candidateB] = makeReconciliationCandidate($version);

    $group = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::TeacherEpayment, 'payer_teacher_id' => $teacherA->id,
        'school_id' => $schoolA->id, 'amount' => 5000, 'status' => PaymentTransactionStatus::Completed, 'paid_at' => now(),
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $group->id, 'candidate_id' => $candidateA->id, 'amount' => 2000, 'allocated_at' => now()]);
    PaymentAllocation::create(['payment_transaction_id' => $group->id, 'candidate_id' => $candidateB->id, 'amount' => 3000, 'allocated_at' => now()]);

    $recorder = User::factory()->create();
    $manual = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payment_type' => 'check', 'school_id' => $schoolA->id,
        'amount' => 500, 'status' => PaymentTransactionStatus::Completed, 'recorded_by_user_id' => $recorder->id,
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $manual->id, 'candidate_id' => $candidateA->id, 'amount' => 500, 'allocated_at' => now()]);

    $details = PaymentReconciliation::paymentDetails($version, $schoolA->id);

    expect($details)->toHaveCount(2)
        ->and($details->firstWhere('transaction.id', $group->id)['amountCents'])->toBe(2000)
        ->and($details->sum('amountCents'))->toBe(2500);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->call('showPaymentDetails', $schoolA->id)
        ->assertSee($teacherA->user->name)
        ->assertSee('of $50.00 payment')
        ->assertSee("Recorded by {$recorder->name}")
        ->assertSee($candidateA->student->user->last_name);
});

test('an Event Manager can open payment details', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    ['school' => $school] = makeReconciliationCandidate($version);

    $eventManager = User::factory()->create();
    grantVersionRole($eventManager, $version, 'Event Manager');

    Livewire::actingAs($eventManager)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertSet('canViewPaymentDetails', true)
        ->call('showPaymentDetails', $school->id)
        ->assertSet('paymentDetailsSchoolId', $school->id);
});

test('a Registration Manager sees no Details button and cannot open payment details', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    ['school' => $school, 'candidate' => $candidate] = makeReconciliationCandidate($version);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'school_id' => $school->id,
        'amount' => 2000, 'status' => PaymentTransactionStatus::Completed,
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $candidate->id, 'amount' => 2000, 'allocated_at' => now()]);

    $registrationManager = User::factory()->create();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    Livewire::actingAs($registrationManager)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertDontSeeHtml('showPaymentDetails(')
        ->call('showPaymentDetails', $school->id)
        ->assertStatus(403);
});

test('a candidate paying for themselves is not repeated as a breakdown line in payment details', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $school, 'candidate' => $candidate] = makeReconciliationCandidate($version);
    $user = $candidate->student->user;

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::CandidateEpayment, 'payer_student_id' => $candidate->student_id,
        'school_id' => $school->id, 'amount' => 2000, 'status' => PaymentTransactionStatus::Completed,
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $candidate->id, 'amount' => 2000, 'allocated_at' => now()]);

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->call('showPaymentDetails', $school->id)
        ->assertSee($user->name)
        ->assertDontSee("for {$user->last_name}, {$user->first_name}")
        ->assertDontSee("{$user->last_name}, {$user->first_name}");
});

test('CSV export lists each school with its teachers\' contact details and dollar amounts', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000]);
    ['school' => $school, 'teacher' => $teacher, 'candidate' => $candidate] = makeReconciliationCandidate($version);
    $teacher->user->update(['cell_phone' => '6095550188']);

    $transaction = PaymentTransaction::create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'school_id' => $school->id,
        'amount' => 1500, 'status' => PaymentTransactionStatus::Completed,
    ]);
    PaymentAllocation::create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $candidate->id, 'amount' => 1500, 'allocated_at' => now()]);

    $response = get(route('events.versions.reports.payment-reconciliation.export', ['version' => $version, 'format' => 'csv']));

    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

    expect(str_getcsv($lines[0]))->toBe(['School', 'Teacher', 'Email', 'Mobile', 'Registered', 'Due', 'Paid', 'Balance'])
        ->and(str_getcsv($lines[1]))->toBe([$school->name, $teacher->user->name, $teacher->user->email, '(609) 555-0188', '1', '20.00', '15.00', '5.00']);
});

test('CSV export honors the search filter', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $wantedSchool] = makeReconciliationCandidate($version);
    ['school' => $otherSchool] = makeReconciliationCandidate($version);

    $content = get(route('events.versions.reports.payment-reconciliation.export', ['version' => $version, 'format' => 'csv', 'search' => $wantedSchool->name]))
        ->streamedContent();

    expect($content)->toContain($wantedSchool->name);
    expect($content)->not->toContain($otherSchool->name);
});

test('CSV export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.payment-reconciliation.export', ['version' => $version, 'format' => 'csv']))
        ->assertForbidden();
});
