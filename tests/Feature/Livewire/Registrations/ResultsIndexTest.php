<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Livewire\Registrations\ResultsIndex;
use App\Models\Candidate;
use App\Models\CoTeacherGrant;
use App\Models\Event;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeResultsIndexTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id]);
}

test('items lists only released Versions the teacher has candidates in, with the resolved-outcome count', function () {
    $teacher = makeResultsIndexTeacher();
    $event = Event::factory()->create();
    $school = School::factory()->create();

    $released = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now(), 'name' => 'Released Version']);
    actingAs($teacher->user);
    Candidate::factory()->create(['version_id' => $released->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Accepted]);
    Candidate::factory()->create(['version_id' => $released->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::NotAccepted]);
    // A withdrawn candidate at the same Version is not a resolved outcome — excluded from the count.
    Candidate::factory()->create(['version_id' => $released->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Withdrew]);

    $notReleased = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'results_released_at' => null, 'name' => 'Not Released Version']);
    Candidate::factory()->registered()->create(['version_id' => $notReleased->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(ResultsIndex::class)
        ->assertSee('Released Version')
        ->assertSee('2') // resolved-outcome count for the released Version
        ->assertDontSee('Not Released Version');
});

test('items is empty for a teacher with no candidates anywhere', function () {
    $teacher = makeResultsIndexTeacher();

    Livewire::actingAs($teacher->user)
        ->test(ResultsIndex::class)
        ->assertOk();
});

test('items includes a released Version reachable only via a co-teaching grant', function () {
    $granting = makeResultsIndexTeacher();
    $coTeacher = makeResultsIndexTeacher();
    $event = Event::factory()->create();
    $school = School::factory()->create();

    $released = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now(), 'name' => 'Shared Released Version']);

    actingAs($granting->user);
    Candidate::factory()->create(['version_id' => $released->id, 'school_id' => $school->id, 'teacher_id' => $granting->id, 'status' => CandidateStatus::Accepted]);

    CoTeacherGrant::create([
        'school_id' => $school->id,
        'granting_teacher_id' => $granting->id,
        'co_teacher_id' => $coTeacher->id,
        'granted_by_user_id' => $granting->user->id,
    ]);

    Livewire::actingAs($coTeacher->user)
        ->test(ResultsIndex::class)
        ->assertSee('Shared Released Version')
        ->assertSee('1');
});
