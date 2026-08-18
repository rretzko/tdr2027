<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Livewire\Sfdi\Events\Index;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeSfdiEventsUser(): User
{
    $user = User::factory()->create();
    Student::factory()->create(['user_id' => $user->id]);

    return $user;
}

test('a teacher without a student profile is forbidden', function () {
    $user = User::factory()->create();
    Teacher::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test(Index::class)->assertForbidden();
});

test('a student sees their own Candidate rows for active Versions, linked to the Event', function () {
    $user = makeSfdiEventsUser();
    $event = Event::factory()->create(['name' => 'All-State Chorus']);
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => EventStatus::Active, 'name' => '2027 All-State']);

    actingAs($user);
    $candidate = Candidate::factory()->create([
        'student_id' => $user->student->id,
        'version_id' => $version->id,
        'status' => CandidateStatus::Eligible,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('2027 All-State')
        ->assertSee('All-State Chorus')
        ->assertSee(route('sfdi.events.candidate', $candidate));
});

test('a Candidate row for a non-active Version is not listed', function () {
    $user = makeSfdiEventsUser();
    $version = Version::factory()->create(['status' => EventStatus::Closed, 'name' => 'Closed Version']);

    actingAs($user);
    Candidate::factory()->create(['student_id' => $user->student->id, 'version_id' => $version->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertDontSee('Closed Version');
});

test('another student\'s Candidate row is never shown', function () {
    $user = makeSfdiEventsUser();
    $otherStudent = Student::factory()->create();
    $version = Version::factory()->create(['status' => EventStatus::Active, 'name' => 'Someone Else\'s Version']);

    actingAs($user);
    Candidate::factory()->create(['student_id' => $otherStudent->id, 'version_id' => $version->id]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertDontSee('Someone Else\'s Version');
});
