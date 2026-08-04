<?php

declare(strict_types=1);

use App\Livewire\Events\Reports\PaymentRoster;
use App\Models\Candidate;
use App\Models\CandidatePayment;
use App\Models\County;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeacherPayment;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('mount aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    Livewire::actingAs($user)
        ->test(PaymentRoster::class, ['version' => $version])
        ->assertStatus(403);
});

test('lists both a teacher payment and a candidate payment, with candidate name only on the latter', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $teacher = Teacher::factory()->create();
    $candidate = Candidate::factory()->registered()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
    ]);

    TeacherPayment::create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'payment_type' => 'check',
        'amount' => 2000,
        'recorded_by_user_id' => $founder->id,
    ]);

    CandidatePayment::create([
        'version_id' => $version->id,
        'candidate_id' => $candidate->id,
        'payment_type' => 'electronic',
        'amount' => 500,
        'paid_at' => now(),
    ]);

    Livewire::actingAs($founder)
        ->test(PaymentRoster::class, ['version' => $version])
        ->assertOk()
        ->assertSee($teacher->user->name)
        ->assertSee($candidate->student->user->name)
        ->assertSee('Check')
        ->assertSee('Electronic');
});

test('a Co-Registration Manager only sees payments within their assigned county', function () {
    actingAs(makeFounder());
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    $schoolA = School::factory()->create(['county_id' => $countyA->id]);
    $schoolB = School::factory()->create(['county_id' => $countyB->id]);
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();

    TeacherPayment::create(['version_id' => $version->id, 'school_id' => $schoolA->id, 'teacher_id' => $teacherA->id, 'payment_type' => 'cash', 'amount' => 1000]);
    TeacherPayment::create(['version_id' => $version->id, 'school_id' => $schoolB->id, 'teacher_id' => $teacherB->id, 'payment_type' => 'cash', 'amount' => 1000]);

    $coRegManager = User::factory()->create();
    grantVersionRole($coRegManager, $version, 'Co-Registration Manager');
    \App\Models\CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $coRegManager->id, 'county_id' => $countyA->id]);

    Livewire::actingAs($coRegManager)
        ->test(PaymentRoster::class, ['version' => $version])
        ->assertOk()
        ->assertSee($teacherA->user->name)
        ->assertDontSee($teacherB->user->name);
});

test('paymentTypeFilter narrows the roster to a single payment type', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();
    $school = School::factory()->create();
    $checkTeacher = Teacher::factory()->create();
    $cashTeacher = Teacher::factory()->create();

    TeacherPayment::create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $checkTeacher->id, 'payment_type' => 'check', 'amount' => 1000]);
    TeacherPayment::create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $cashTeacher->id, 'payment_type' => 'cash', 'amount' => 1000]);

    Livewire::actingAs($founder)
        ->test(PaymentRoster::class, ['version' => $version])
        ->set('paymentTypeFilter', 'cash')
        ->assertSee($cashTeacher->user->name)
        ->assertDontSee($checkTeacher->user->name);
});

test('PDF export returns a PDF for an authorized user', function () {
    $founder = makeFounder();
    actingAs($founder);
    $version = Version::factory()->create();

    get(route('events.versions.reports.payment-roster.pdf', $version))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('PDF export aborts with 403 for a user with no relevant role', function () {
    $user = User::factory()->create();
    $version = Version::factory()->create();

    actingAs($user);

    get(route('events.versions.reports.payment-roster.pdf', $version))
        ->assertForbidden();
});
