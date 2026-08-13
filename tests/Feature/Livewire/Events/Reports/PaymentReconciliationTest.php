<?php

declare(strict_types=1);

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

    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $version])
        ->assertSee($school->name)
        ->assertSee('25.00') // due: registration $20 + participation $5
        ->assertSee('15.00'); // paid
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

    get(route('events.versions.reports.payment-reconciliation.pdf', $version))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('PDF export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.payment-reconciliation.pdf', $version))
        ->assertForbidden();
});
