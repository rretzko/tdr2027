<?php

declare(strict_types=1);

use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Livewire\Events\Reports\ParticipatingSchools;
use App\Mail\PacketReceivedMail;
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
use App\Models\VersionTeacherPacket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * @return array{school: School, teacher: Teacher}
 */
function makeParticipatingSchoolPair(Version $version, ?County $county = null, int $registeredCount = 1): array
{
    $county ??= County::factory()->create();
    $school = School::factory()->create(['county_id' => $county->id]);
    $teacher = Teacher::factory()->create();

    Candidate::factory()->registered()->count($registeredCount)->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    return ['school' => $school, 'teacher' => $teacher];
}

/**
 * A completed, 100%-allocated payment for one candidate — the only shape
 * that counts toward ParticipatingSchools::baseRows()'s "paid" total (see
 * that method's own comment on why an unallocated lump-sum payment does not).
 */
function payCandidate(Candidate $candidate, int $amountCents): void
{
    $transaction = PaymentTransaction::create([
        'version_id' => $candidate->version_id,
        'source' => PaymentSource::Manual,
        'payer_teacher_id' => $candidate->teacher_id,
        'school_id' => $candidate->school_id,
        'amount' => $amountCents,
        'status' => PaymentTransactionStatus::Completed,
        'payment_type' => 'check',
        'paid_at' => now(),
    ]);

    PaymentAllocation::create([
        'payment_transaction_id' => $transaction->id,
        'candidate_id' => $candidate->id,
        'amount' => $amountCents,
        'allocated_at' => now(),
    ]);
}

test('mount aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->assertStatus(403);
});

test('lists a school/teacher pair with the correct due, paid, and balance amounts', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000]);
    ['school' => $school, 'teacher' => $teacher] = makeParticipatingSchoolPair($version, registeredCount: 2);

    $candidate = Candidate::where('school_id', $school->id)->where('teacher_id', $teacher->id)->first();
    payCandidate($candidate, 1500);

    Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->assertOk()
        ->assertSee($school->name)
        ->assertSee('40.00') // due: 2 candidates * $20.00
        ->assertSee('15.00') // paid
        ->assertSee('25.00 due'); // balance
});

test('a lump-sum payment recorded via savePayment does not count toward the balance until it is allocated', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000]);
    ['school' => $school, 'teacher' => $teacher] = makeParticipatingSchoolPair($version, registeredCount: 1);

    Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->call('openPayment', $school->id, $teacher->id)
        ->set('paymentType', 'check')
        ->set('amount', '20.00')
        ->call('savePayment');

    Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->assertSee('20.00 due'); // full balance still due — the payment is unallocated
});

test('togglePacket creates a received packet row and toggling again clears it', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher] = makeParticipatingSchoolPair($version);

    $component = Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->call('togglePacket', $school->id, $teacher->id);

    $packet = VersionTeacherPacket::where('version_id', $version->id)
        ->where('school_id', $school->id)
        ->where('teacher_id', $teacher->id)
        ->first();

    expect($packet)->not->toBeNull();
    expect($packet->isReceived())->toBeTrue();
    expect($packet->received_by_user_id)->toBe($founder->id);

    $component->call('togglePacket', $school->id, $teacher->id);

    expect($packet->refresh()->isReceived())->toBeFalse();
});

test('togglePacket aborts with 403 for a school/teacher pair outside the acting Co-Registration Manager\'s county', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    ['school' => $schoolB, 'teacher' => $teacherB] = makeParticipatingSchoolPair($version, $countyB);

    $coRegManager = User::factory()->create();
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $coRegManager->id, 'county_id' => $countyA->id]);

    Livewire::actingAs($coRegManager)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->call('togglePacket', $schoolB->id, $teacherB->id)
        ->assertStatus(403);
});

test('savePayment records an unallocated payment_transactions row with the acting user attributed', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher] = makeParticipatingSchoolPair($version);

    Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->call('openPayment', $school->id, $teacher->id)
        ->set('paymentType', 'check')
        ->set('amount', '25.50')
        ->set('referenceNumber', 'CHK-1001')
        ->set('comments', 'Paid in full')
        ->call('savePayment')
        ->assertHasNoErrors();

    $payment = PaymentTransaction::where('version_id', $version->id)->where('school_id', $school->id)->where('payer_teacher_id', $teacher->id)->first();

    expect($payment)->not->toBeNull();
    expect($payment->amount)->toBe(2550);
    expect($payment->reference_number)->toBe('CHK-1001');
    expect($payment->recorded_by_user_id)->toBe($founder->id);
    expect($payment->getRawOriginal('source'))->toBe('manual');
    expect($payment->allocations)->toHaveCount(0);
});

test('savePayment rejects an invalid payment type', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher] = makeParticipatingSchoolPair($version);

    Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->call('openPayment', $school->id, $teacher->id)
        ->set('paymentType', 'electronic')
        ->set('amount', '10.00')
        ->call('savePayment')
        ->assertHasErrors(['paymentType']);
});

test('sendConfirmations emails every teacher with a received but unconfirmed packet and stamps the row', function () {
    Mail::fake();
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher] = makeParticipatingSchoolPair($version);

    $packet = VersionTeacherPacket::create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'received_at' => now(),
        'received_by_user_id' => $founder->id,
    ]);

    Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->call('sendConfirmations');

    Mail::assertSent(PacketReceivedMail::class, fn ($mail) => $mail->hasTo($teacher->user->email));

    expect($packet->refresh()->confirmation_sent_at)->not->toBeNull();
    expect($packet->confirmation_sent_by_user_id)->toBe($founder->id);
});

test('sendConfirmations does not re-email a packet whose confirmation was already sent', function () {
    Mail::fake();
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $school, 'teacher' => $teacher] = makeParticipatingSchoolPair($version);

    VersionTeacherPacket::create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'received_at' => now(),
        'received_by_user_id' => $founder->id,
        'confirmation_sent_at' => now(),
        'confirmation_sent_by_user_id' => $founder->id,
    ]);

    Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->call('sendConfirmations');

    Mail::assertNotSent(PacketReceivedMail::class);
});

test('packetFilter outstanding shows only schools without a received packet', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    ['school' => $receivedSchool, 'teacher' => $receivedTeacher] = makeParticipatingSchoolPair($version);
    ['school' => $outstandingSchool] = makeParticipatingSchoolPair($version);

    VersionTeacherPacket::create([
        'version_id' => $version->id,
        'school_id' => $receivedSchool->id,
        'teacher_id' => $receivedTeacher->id,
        'received_at' => now(),
        'received_by_user_id' => $founder->id,
    ]);

    Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->set('packetFilter', 'outstanding')
        ->assertSee($outstandingSchool->name)
        ->assertDontSee($receivedSchool->name);
});

test('sorting by Registered, Due, and Balance orders rows by their underlying values', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000]);

    ['school' => $schoolFew] = makeParticipatingSchoolPair($version, registeredCount: 1);
    ['school' => $schoolMany, 'teacher' => $teacherMany] = makeParticipatingSchoolPair($version, registeredCount: 3);

    $candidateMany = Candidate::where('school_id', $schoolMany->id)->where('teacher_id', $teacherMany->id)->first();
    payCandidate($candidateMany, 6000);

    $component = Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->call('sortBy', 'count');

    $component->assertSeeInOrder([$schoolFew->name, $schoolMany->name]);

    $component->call('sortBy', 'due');
    $component->assertSeeInOrder([$schoolFew->name, $schoolMany->name]);

    $component->call('sortBy', 'balance');
    $component->assertSeeInOrder([$schoolMany->name, $schoolFew->name]);
});

test('PDF export returns a PDF for an authorized user', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    makeParticipatingSchoolPair($version);

    get(route('events.versions.reports.participating-schools.pdf', $version))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('PDF export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.participating-schools.pdf', $version))
        ->assertForbidden();
});
