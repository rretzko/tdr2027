<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Livewire\Registrations\Results;
use App\Models\AuditionResult;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeResultsTeacher(): Teacher
{
    $user = User::factory()->create();

    return Teacher::factory()->create(['user_id' => $user->id]);
}

test('mount aborts with 403 when the teacher has no standing in the Version', function () {
    $teacher = makeResultsTeacher();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now()]);

    Livewire::actingAs($teacher->user)
        ->test(Results::class, ['version' => $version])
        ->assertStatus(403);
});

test('mount aborts with 403 when results have not been released even if the teacher has candidates', function () {
    $teacher = makeResultsTeacher();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'results_released_at' => null]);
    $school = School::factory()->create();
    actingAs($teacher->user);
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Accepted]);

    Livewire::actingAs($teacher->user)
        ->test(Results::class, ['version' => $version])
        ->assertStatus(403);
});

test('candidates lists only this teacher\'s resolved-outcome candidates, with score/ensemble data', function () {
    $teacher = makeResultsTeacher();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now()]);
    $school = School::factory()->create();
    $voicePart = VoicePart::factory()->create();
    $ensemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus']);

    actingAs($teacher->user);
    $accepted = Candidate::factory()->create([
        'version_id' => $version->id,
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'voice_part_id' => $voicePart->id,
        'status' => CandidateStatus::Accepted,
        'accepted_ensemble_id' => $ensemble->id,
    ]);
    AuditionResult::create([
        'candidate_id' => $accepted->id,
        'version_id' => $version->id,
        'voice_part_id' => $voicePart->id,
        'school_id' => $school->id,
        'voice_part_order_by' => 1,
        'score_count' => 1,
        'total' => 88,
    ]);

    // Still-registered candidate: not a resolved outcome, must not appear.
    Candidate::factory()->registered()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id]);

    // Another teacher's accepted candidate must not appear either.
    $otherTeacher = makeResultsTeacher();
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $otherTeacher->id, 'status' => CandidateStatus::Accepted]);

    $html = Livewire::actingAs($teacher->user)
        ->test(Results::class, ['version' => $version])
        ->assertSee('88')
        ->assertSee('Mixed Chorus')
        ->html();

    // Responsive markup renders both the mobile-card and desktop-table
    // variants in the same HTML (CSS-hidden, not DOM-removed) — one
    // "Accepted" badge per variant, for the one qualifying candidate.
    expect(substr_count($html, 'Accepted'))->toBe(2);
});

test('switcherOptions only lists Versions whose results have been released', function () {
    $teacher = makeResultsTeacher();
    $event = Event::factory()->create();

    $released = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now()]);
    $notReleased = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'results_released_at' => null, 'name' => 'Not Released Version']);

    $school = School::factory()->create();
    actingAs($teacher->user);
    Candidate::factory()->create(['version_id' => $released->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Accepted]);
    Candidate::factory()->registered()->create(['version_id' => $notReleased->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id]);

    Livewire::actingAs($teacher->user)
        ->test(Results::class, ['version' => $released])
        ->assertDontSee('Not Released Version');
});
