<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Livewire\Events\TabRoom\CloseAudition;
use App\Mail\AuditionResultsAvailableMail;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('mount succeeds for a Tab Room Manager', function () {
    $manager = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    Livewire::actingAs($manager)
        ->test(CloseAudition::class, ['version' => $version])
        ->assertOk();
});

test('mount aborts with 403 for a user with no Tab Room Manager role', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);

    Livewire::actingAs($user)
        ->test(CloseAudition::class, ['version' => $version])
        ->assertStatus(403);
});

test('close marks the Version closed and stamps results_released_at', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    Livewire::actingAs($manager)
        ->test(CloseAudition::class, ['version' => $version])
        ->call('close');

    $version->refresh();
    expect($version->getRawOriginal('status'))->toBe('closed');
    expect($version->results_released_at)->not->toBeNull();
});

test('close aborts with 422 when the Version is not active', function () {
    $manager = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    Livewire::actingAs($manager)
        ->test(CloseAudition::class, ['version' => $version])
        ->call('close')
        ->assertStatus(422);
});

test('reopen clears results_released_at and reactivates the Version', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now()]);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    Livewire::actingAs($manager)
        ->test(CloseAudition::class, ['version' => $version])
        ->call('reopen');

    $version->refresh();
    expect($version->getRawOriginal('status'))->toBe('active');
    expect($version->results_released_at)->toBeNull();
});

test('reopen aborts with 422 when the Version is not closed', function () {
    $manager = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    Livewire::actingAs($manager)
        ->test(CloseAudition::class, ['version' => $version])
        ->call('reopen')
        ->assertStatus(422);
});

test('closing with emailTeachers checked sends the results-available mail to each distinct participating teacher once', function () {
    Mail::fake();

    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $school = School::factory()->create();
    $teacherA = Teacher::factory()->create(['user_id' => User::factory()->create(['email' => 'teacher-a@example.com'])->id]);
    $teacherB = Teacher::factory()->create(['user_id' => User::factory()->create(['email' => 'teacher-b@example.com'])->id]);

    // Two candidates share teacherA — the mail must go to teacherA once, not twice.
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacherA->id, 'status' => CandidateStatus::Accepted]);
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacherA->id, 'status' => CandidateStatus::NotAccepted]);
    // teacherB has only a still-registered candidate — no resolved outcome, so no mail.
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacherB->id]);

    Livewire::actingAs($manager)
        ->test(CloseAudition::class, ['version' => $version])
        ->set('emailTeachers', true)
        ->call('close');

    Mail::assertSent(AuditionResultsAvailableMail::class, 1);
    Mail::assertSent(AuditionResultsAvailableMail::class, fn ($mail) => $mail->hasTo('teacher-a@example.com'));
    Mail::assertNotSent(AuditionResultsAvailableMail::class, fn ($mail) => $mail->hasTo('teacher-b@example.com'));
});

test('closing without emailTeachers checked sends no mail', function () {
    Mail::fake();

    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $school = School::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => User::factory()->create()->id]);
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Accepted]);

    Livewire::actingAs($manager)
        ->test(CloseAudition::class, ['version' => $version])
        ->call('close');

    Mail::assertNothingSent();
});
