<?php

declare(strict_types=1);

use App\Enums\ReadinessStatus;
use App\Livewire\Events\CreateEvent;
use App\Livewire\Events\Show;
use App\Livewire\Events\VersionEdit;
use App\Livewire\Events\VersionReadinessChecklist;
use App\Livewire\Events\VersionSetupQuestions;
use App\Models\County;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionCounty;
use App\Models\VersionEpaymentConfig;
use App\Services\VersionCloningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 12:00:00');
});

function setupQuestionsManager(Version $version): User
{
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    grantVersionRole($user, $version, 'Event Manager');

    return $user;
}

test('only an Event Manager (or the Founder) can open the setup questions', function () {
    $version = readinessVersion();
    $registrationManager = User::factory()->create();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    Livewire::actingAs($registrationManager)
        ->test(VersionSetupQuestions::class, ['version' => $version])
        ->assertStatus(403);

    Livewire::actingAs(setupQuestionsManager($version))
        ->test(VersionSetupQuestions::class, ['version' => $version])
        ->assertSee('How do students audition?')
        ->assertOk();
});

test('a first run starts with every question unanswered', function () {
    $version = readinessVersion();

    Livewire::actingAs(makeFounder())
        ->test(VersionSetupQuestions::class, ['version' => $version])
        ->assertSet('audition_type', '')
        ->assertSet('application_type', '')
        ->assertSet('mail_required', '')
        ->assertSet('teacher_payments', '')
        ->assertSet('student_payments', '')
        ->assertSet('optional.caps', '');
});

test('choosing a paper application pre-selects mailing, but never overrides an explicit answer', function () {
    $version = readinessVersion();

    Livewire::actingAs(makeFounder())
        ->test(VersionSetupQuestions::class, ['version' => $version])
        ->set('application_type', 'pdf')
        ->assertSet('mail_required', 'yes')
        ->set('mail_required', 'no')
        ->set('application_type', 'eapplication')
        ->set('application_type', 'pdf')
        ->assertSet('mail_required', 'no');
});

test('saving applies the answers and goes to the checklist', function () {
    $version = readinessVersion();

    Livewire::actingAs(setupQuestionsManager($version))
        ->test(VersionSetupQuestions::class, ['version' => $version])
        ->set('audition_type', 'remote')
        ->set('upload_type', 'audio')
        ->set('recording_names', "Scales\nSolo\n\n")
        ->set('application_type', 'eapplication')
        ->set('mail_required', 'no')
        ->set('teacher_payments', 'check_only')
        ->set('student_payments', 'no')
        ->set('ensemble_names', "Mixed Chorus\nTreble Choir")
        ->set('judge_count', '2')
        ->set('optional.obligations', 'no')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('events.versions.readiness', $version));

    $version->refresh();
    expect($version->uploadFiles()->pluck('name')->all())->toBe(['Scales', 'Solo'])
        ->and($version->judge_count)->toBe(2)
        ->and(readinessStatus($version, 'version.audition_format'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'version.epayment.decision'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'event.epayment.credentials'))->toBe(ReadinessStatus::NotApplicable)
        ->and(readinessStatus($version, 'version.obligations'))->toBe(ReadinessStatus::Done)
        ->and(readinessStatus($version, 'event.ensembles'))->toBe(ReadinessStatus::Done);
});

test('saving with nothing answered changes nothing', function () {
    $version = readinessVersion();
    $before = $version->fresh()->getAttributes();

    Livewire::actingAs(makeFounder())
        ->test(VersionSetupQuestions::class, ['version' => $version])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('events.versions.readiness', $version));

    expect($version->fresh()->getAttributes())->toBe($before)
        ->and($version->readinessReviews()->count())->toBe(0);
});

test('lists are capped at ten lines and audition length must be realistic', function () {
    $version = readinessVersion();

    Livewire::actingAs(makeFounder())
        ->test(VersionSetupQuestions::class, ['version' => $version])
        ->set('audition_type', 'in_person')
        ->set('audition_timeslot', '2')
        ->set('ensemble_names', implode("\n", range(1, 11)))
        ->call('save')
        ->assertHasErrors(['audition_timeslot', 'ensemble_names']);
});

test('a feature that already has data shows as already set up instead of yes/no', function () {
    $version = readinessVersion();
    VersionCounty::create(['version_id' => $version->id, 'county_id' => County::factory()->create()->id]);

    Livewire::actingAs(makeFounder())
        ->test(VersionSetupQuestions::class, ['version' => $version])
        ->assertSee('Already set up — 1 county selected')
        ->assertSet('optional.counties', 'yes');
});

test('creating a new event sends its manager to the setup questions', function () {
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id, 'onboarding_completed_at' => now()]);
    $organization = Organization::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CreateEvent::class)
        ->set('name', 'Spring Honor Choir')
        ->set('organization_id', (string) $organization->id)
        ->call('create')
        ->assertHasNoErrors();

    $version = Version::whereHas('event', fn ($q) => $q->where('name', 'Spring Honor Choir'))->firstOrFail();

    $component->assertRedirect(route('events.versions.setup-questions', $version));
});

test('adding an event\'s first version goes to the setup questions; a cloned version does not', function () {
    $event = Event::factory()->create();

    Livewire::actingAs(makeFounder())
        ->test(Show::class, ['event' => $event])
        ->set('new_name', 'First Version')
        ->set('new_senior_class_of', '2027')
        ->call('createVersion')
        ->assertRedirect(route('events.versions.setup-questions', Version::where('name', 'First Version')->firstOrFail()));

    Livewire::actingAs(makeFounder())
        ->test(Show::class, ['event' => $event])
        ->set('new_name', 'Second Version')
        ->set('new_senior_class_of', '2028')
        ->call('createVersion')
        ->assertNoRedirect();
});

test('the checklist offers the setup questions to Event Managers only', function () {
    $version = readinessVersion();
    $registrationManager = User::factory()->create();
    grantVersionRole($registrationManager, $version, 'Registration Manager');

    Livewire::actingAs(setupQuestionsManager($version))
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->assertSee(route('events.versions.setup-questions', $version));

    Livewire::actingAs($registrationManager)
        ->test(VersionReadinessChecklist::class, ['version' => $version])
        ->assertDontSee(route('events.versions.setup-questions', $version));
});

test('Configure → Payments saves "online only", and clears it when teachers can\'t pay online', function () {
    $version = readinessVersion();
    $user = setupQuestionsManager($version);

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version])
        ->set('epayment_teacher', true)
        ->set('online_payment_required', true)
        ->call('saveEpaymentFlags')
        ->assertHasNoErrors();

    expect($version->fresh()->onlinePaymentRequired())->toBeTrue();

    Livewire::actingAs($user)
        ->test(VersionEdit::class, ['version' => $version->fresh()])
        ->assertSet('online_payment_required', true)
        ->set('epayment_teacher', false)
        ->call('saveEpaymentFlags');

    expect($version->versionEpaymentConfig()->first()->online_payment_required)->toBeFalse();
});

test('cloning carries the "online only" rule forward', function () {
    $source = readinessVersion();
    VersionEpaymentConfig::create(['version_id' => $source->id, 'epayment_teacher' => true, 'epayment_student' => true, 'online_payment_required' => true]);

    $clone = app(VersionCloningService::class)->cloneFrom(
        $source,
        ['name' => 'Next year', 'short_name' => null, 'senior_class_of' => 2028],
        User::factory()->create(),
    );

    expect($clone->fresh()->onlinePaymentRequired())->toBeTrue();
});
