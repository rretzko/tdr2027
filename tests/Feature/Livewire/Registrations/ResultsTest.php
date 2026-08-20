<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Livewire\Registrations\Results;
use App\Models\AuditionResult;
use App\Models\Candidate;
use App\Models\CoTeacherGrant;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\School;
use App\Models\Student;
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

test('mount succeeds for a co-teacher granted access to another teacher\'s resolved-outcome candidate', function () {
    $granting = makeResultsTeacher();
    $coTeacher = makeResultsTeacher();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now()]);
    $school = School::factory()->create();

    actingAs($granting->user);
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $granting->id, 'status' => CandidateStatus::Accepted]);

    CoTeacherGrant::create([
        'school_id' => $school->id,
        'granting_teacher_id' => $granting->id,
        'co_teacher_id' => $coTeacher->id,
        'granted_by_user_id' => $granting->user->id,
    ]);

    Livewire::actingAs($coTeacher->user)
        ->test(Results::class, ['version' => $version])
        ->assertOk();
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

test('sortBy toggles candidate ordering, defaulting to Name ascending', function () {
    $teacher = makeResultsTeacher();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now()]);
    $school = School::factory()->create();
    $voicePart = VoicePart::factory()->create();

    actingAs($teacher->user);

    $lowUser = User::factory()->create(['first_name' => 'Zed', 'last_name' => 'Zephyr']);
    $lowStudent = Student::factory()->create(['user_id' => $lowUser->id]);
    $low = Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'student_id' => $lowStudent->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Accepted]);
    AuditionResult::create(['candidate_id' => $low->id, 'version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'school_id' => $school->id, 'voice_part_order_by' => 1, 'score_count' => 1, 'total' => 40]);

    $highUser = User::factory()->create(['first_name' => 'Amy', 'last_name' => 'Alpha']);
    $highStudent = Student::factory()->create(['user_id' => $highUser->id]);
    $high = Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'student_id' => $highStudent->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::NotAccepted]);
    AuditionResult::create(['candidate_id' => $high->id, 'version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'school_id' => $school->id, 'voice_part_order_by' => 1, 'score_count' => 1, 'total' => 90]);

    $component = Livewire::actingAs($teacher->user)->test(Results::class, ['version' => $version]);

    // Default sort: Name ascending — "Alpha, Amy" sorts before "Zephyr, Zed".
    $component->assertSeeInOrder(['Alpha', 'Zephyr']);

    $component->call('sortBy', 'score')
        ->assertSet('sortColumn', 'score')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['40', '90']);

    // Clicking the same column again flips direction.
    $component->call('sortBy', 'score')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeInOrder(['90', '40']);
});

test('sortBy voice_part orders by voice_parts.sort_order, not name/abbr', function () {
    $teacher = makeResultsTeacher();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now()]);
    $school = School::factory()->create();

    // Names/abbreviations deliberately reversed against sort_order, so a
    // pass sorting by name/abbr instead of sort_order would fail this.
    $laterPart = VoicePart::factory()->create(['name' => 'Alto', 'abbr' => 'A', 'sort_order' => 10]);
    $earlierPart = VoicePart::factory()->create(['name' => 'Tenor', 'abbr' => 'T', 'sort_order' => 1]);

    actingAs($teacher->user);
    $laterCandidate = Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'voice_part_id' => $laterPart->id, 'status' => CandidateStatus::Accepted]);
    $earlierCandidate = Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'voice_part_id' => $earlierPart->id, 'status' => CandidateStatus::Accepted]);

    // Assert via each candidate's own unique id cell (voicePart->abbr is a
    // single letter here and not reliably unique/locatable in the page's
    // full HTML) — sort_order 1 (Tenor) must render before sort_order 10 (Alto).
    Livewire::actingAs($teacher->user)
        ->test(Results::class, ['version' => $version])
        ->call('sortBy', 'voice_part')
        ->assertSeeInOrder([(string) $earlierCandidate->id, (string) $laterCandidate->id]);
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

test('the school filter is hidden when the roster has only one school', function () {
    $teacher = makeResultsTeacher();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now()]);
    $school = School::factory()->create();

    actingAs($teacher->user);
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $school->id, 'teacher_id' => $teacher->id, 'status' => CandidateStatus::Accepted]);

    Livewire::actingAs($teacher->user)
        ->test(Results::class, ['version' => $version])
        ->assertDontSee('All schools');
});

test('the school filter appears and narrows the roster once candidates span more than one school', function () {
    $granting = makeResultsTeacher();
    $coTeacher = makeResultsTeacher();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', 'results_released_at' => now()]);
    $schoolA = School::factory()->create(['name' => 'Aardvark Academy']);
    $schoolB = School::factory()->create(['name' => 'Zephyr School']);

    actingAs($granting->user);
    $studentAtA = Student::factory()->create();
    $studentAtA->user->update(['first_name' => 'Sam', 'last_name' => 'AtSchoolA']);
    $studentAtB = Student::factory()->create();
    $studentAtB->user->update(['first_name' => 'Robin', 'last_name' => 'AtSchoolB']);

    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $schoolA->id, 'teacher_id' => $granting->id, 'student_id' => $studentAtA->id, 'status' => CandidateStatus::Accepted]);
    Candidate::factory()->create(['version_id' => $version->id, 'school_id' => $schoolB->id, 'teacher_id' => $coTeacher->id, 'student_id' => $studentAtB->id, 'status' => CandidateStatus::Accepted]);

    CoTeacherGrant::create([
        'school_id' => $schoolA->id,
        'granting_teacher_id' => $granting->id,
        'co_teacher_id' => $coTeacher->id,
        'granted_by_user_id' => $granting->user->id,
    ]);

    $component = Livewire::actingAs($coTeacher->user)
        ->test(Results::class, ['version' => $version]);

    $component->assertSee('All schools');
    $component->assertSee('AtSchoolA, Sam');
    $component->assertSee('AtSchoolB, Robin');

    $component->set('schoolFilter', (string) $schoolA->id);
    $component->assertSee('AtSchoolA, Sam');
    $component->assertDontSee('AtSchoolB, Robin');

    // The dropdown's own option list stays full even while filtered.
    $component->assertSee('All schools');
    $component->assertSee('Zephyr School');
});
