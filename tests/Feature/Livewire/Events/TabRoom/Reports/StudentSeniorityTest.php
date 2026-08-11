<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Livewire\Events\TabRoom\Reports\StudentSeniority;
use App\Models\Candidate;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('mount succeeds for a Tab Room Manager', function () {
    $manager = User::factory()->create();
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    Livewire::actingAs($manager)
        ->test(StudentSeniority::class, ['version' => $version])
        ->assertOk();
});

test('mount aborts with 403 for a user with no Tab Room Manager role', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StudentSeniority::class, ['version' => $version])
        ->assertStatus(403);
});

test('choosing an Ensemble shows a Y/N grid across sibling Versions of the same Event', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active', 'senior_class_of' => 2027]);
    $priorVersion = Version::factory()->create(['event_id' => $event->id, 'senior_class_of' => 2026]);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $ensemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus']);
    $voicePart = VoicePart::factory()->create();

    $accepted = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Accepted, 'accepted_ensemble_id' => $ensemble->id]);
    // Same student, accepted the prior year too — a fully-populated grid row.
    Candidate::factory()->create(['version_id' => $priorVersion->id, 'student_id' => $accepted->student_id, 'status' => CandidateStatus::Accepted]);

    Livewire::actingAs($manager)
        ->test(StudentSeniority::class, ['version' => $version])
        ->set('ensembleId', $ensemble->id)
        ->assertSee($accepted->student->user->sort_name)
        ->assertSee('2027')
        ->assertSee('2026');
});
