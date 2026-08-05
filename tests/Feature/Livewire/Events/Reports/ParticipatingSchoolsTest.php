<?php

declare(strict_types=1);

use App\Livewire\Events\Reports\ParticipatingSchools;
use App\Mail\PacketReceivedMail;
use App\Models\Candidate;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeacherPayment;
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

    TeacherPayment::create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'payment_type' => 'check',
        'amount' => 1500,
        'recorded_by_user_id' => $founder->id,
    ]);

    Livewire::actingAs($founder)
        ->test(ParticipatingSchools::class, ['version' => $version])
        ->assertOk()
        ->assertSee($school->name)
        ->assertSee('40.00') // due: 2 candidates * $20.00
        ->assertSee('15.00') // paid
        ->assertSee('25.00 due'); // balance
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

test('savePayment records a teacher_payments row with the acting user attributed', function () {
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

    $payment = TeacherPayment::where('version_id', $version->id)->where('school_id', $school->id)->where('teacher_id', $teacher->id)->first();

    expect($payment)->not->toBeNull();
    expect($payment->amount)->toBe(2550);
    expect($payment->reference_number)->toBe('CHK-1001');
    expect($payment->recorded_by_user_id)->toBe($founder->id);
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

    TeacherPayment::create([
        'version_id' => $version->id,
        'school_id' => $schoolMany->id,
        'teacher_id' => $teacherMany->id,
        'payment_type' => 'check',
        'amount' => 6000,
        'recorded_by_user_id' => $founder->id,
    ]);

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
