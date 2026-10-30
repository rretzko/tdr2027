<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\VersionDateType;
use App\Livewire\Founder\EventDeadlines;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionEnsembleOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function makeEventDeadlinesFounder(): User
{
    // rick@mfrholdings.com may already exist from seeded data — reuse it rather
    // than colliding with the unique email constraint.
    return User::where('email', 'rick@mfrholdings.com')->first()
        ?? User::factory()->create(['email' => 'rick@mfrholdings.com']);
}

test('a non-founder cannot view the event deadlines page', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('founder.event-deadlines'))->assertNotFound();
});

test('the founder can view the event deadlines page', function () {
    $founder = makeEventDeadlinesFounder();

    actingAs($founder)->get(route('founder.event-deadlines'))->assertOk()->assertSeeText('Event Deadlines');
});

test('only active and sandbox versions are shown, not inactive or closed', function () {
    $founder = makeEventDeadlinesFounder();
    $event = Event::factory()->create(['name' => 'All State Choir']);
    Version::factory()->for($event)->create(['name' => 'Active Version', 'status' => EventStatus::Active]);
    Version::factory()->for($event)->create(['name' => 'Sandbox Version', 'status' => EventStatus::Sandbox]);
    Version::factory()->for($event)->create(['name' => 'Inactive Version', 'status' => EventStatus::Inactive]);
    Version::factory()->for($event)->create(['name' => 'Closed Version', 'status' => EventStatus::Closed]);

    Livewire::actingAs($founder)
        ->test(EventDeadlines::class)
        ->assertSee('All State Choir')
        ->assertSee('Active Version')
        ->assertSee('Sandbox Version')
        ->assertDontSee('Inactive Version')
        ->assertDontSee('Closed Version');
});

test('an event with no active or sandbox versions is not shown at all', function () {
    $founder = makeEventDeadlinesFounder();
    $event = Event::factory()->create(['name' => 'Retired Festival']);
    Version::factory()->for($event)->create(['status' => EventStatus::Closed]);

    Livewire::actingAs($founder)
        ->test(EventDeadlines::class)
        ->assertDontSee('Retired Festival');
});

test('the next upcoming deadline is listed before a past deadline', function () {
    $founder = makeEventDeadlinesFounder();
    $event = Event::factory()->create(['name' => 'Spring Showcase']);
    $version = Version::factory()->for($event)->create(['status' => EventStatus::Active]);

    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::Teacher,
        'start_at' => now()->subDays(10),
    ]);
    VersionDate::create([
        'version_id' => $version->id,
        'date_type' => VersionDateType::PostmarkDeadline,
        'start_at' => now()->addDays(5),
    ]);

    $html = Livewire::actingAs($founder)
        ->test(EventDeadlines::class)
        ->html();

    expect(strpos($html, 'Postmark Deadline'))->toBeLessThan(strpos($html, 'Teacher Access'));
});

test('registrant count includes registered and every later status so it holds steady after results', function () {
    $founder = makeEventDeadlinesFounder();
    $version = Version::factory()->create(['name' => 'Counted Version', 'status' => EventStatus::Active]);

    // Counted: Registered plus each post-registration outcome
    Candidate::factory()->count(2)->create(['version_id' => $version->id, 'status' => CandidateStatus::Registered]);
    foreach ([CandidateStatus::Adjudicated, CandidateStatus::NoShow, CandidateStatus::Incomplete, CandidateStatus::Accepted, CandidateStatus::NotAccepted, CandidateStatus::Declined] as $status) {
        Candidate::factory()->create(['version_id' => $version->id, 'status' => $status]);
    }

    // Not counted: never registered, or withdrawn/removed
    foreach ([CandidateStatus::Eligible, CandidateStatus::Pending, CandidateStatus::Withdrew, CandidateStatus::TeacherWithdrawn, CandidateStatus::Removed] as $status) {
        Candidate::factory()->create(['version_id' => $version->id, 'status' => $status]);
    }

    Livewire::actingAs($founder)
        ->test(EventDeadlines::class)
        ->assertSeeInOrder(['Counted Version', 'Registrant Count:', '8', 'Accepted:']);
});

test('each version shows accepted counts per ensemble in tab room order, zero before results', function () {
    $founder = makeEventDeadlinesFounder();
    $event = Event::factory()->create();
    $version = Version::factory()->for($event)->create(['name' => 'Results Version', 'status' => EventStatus::Active]);
    $mixed = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus']);
    $treble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Treble Chorus']);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $treble->id, 'order_by' => 1]);
    VersionEnsembleOrder::create(['version_id' => $version->id, 'ensemble_id' => $mixed->id, 'order_by' => 2]);

    Candidate::factory()->count(2)->create(['version_id' => $version->id, 'status' => CandidateStatus::Accepted, 'accepted_ensemble_id' => $treble->id]);
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::NotAccepted]);
    Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Registered]);

    Livewire::actingAs($founder)
        ->test(EventDeadlines::class)
        ->assertViewHas('accepted', fn (array $accepted): bool => $accepted[$version->id] === [
            'total' => 2,
            'ensembles' => [
                ['name' => 'Treble Chorus', 'count' => 2],
                ['name' => 'Mixed Chorus', 'count' => 0],
            ],
        ])
        ->assertSeeInOrder(['Accepted:', '2', 'Treble Chorus', '2', 'Mixed Chorus', '0']);
});
