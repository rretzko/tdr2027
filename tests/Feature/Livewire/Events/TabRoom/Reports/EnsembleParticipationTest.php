<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Livewire\Events\TabRoom\Reports\EnsembleParticipation;
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
        ->test(EnsembleParticipation::class, ['version' => $version])
        ->assertOk();
});

test('mount aborts with 403 for a user with no Tab Room Manager role', function () {
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EnsembleParticipation::class, ['version' => $version])
        ->assertStatus(403);
});

test('choosing an Ensemble lists only its accepted members with contact info', function () {
    $manager = User::factory()->create();
    actingAs($manager);
    $event = Event::factory()->create();
    $version = Version::factory()->create(['event_id' => $event->id, 'status' => 'active']);
    grantVersionRole($manager, $version, 'Tab Room Manager');

    $ensemble = Ensemble::factory()->create(['event_id' => $event->id, 'name' => 'Mixed Chorus']);
    $voicePart = VoicePart::factory()->create();

    $accepted = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::Accepted, 'accepted_ensemble_id' => $ensemble->id]);
    $notAccepted = Candidate::factory()->create(['version_id' => $version->id, 'voice_part_id' => $voicePart->id, 'status' => CandidateStatus::NotAccepted]);

    Livewire::actingAs($manager)
        ->test(EnsembleParticipation::class, ['version' => $version])
        ->set('ensembleId', $ensemble->id)
        ->assertSee($accepted->student->user->sort_name)
        ->assertDontSee($notAccepted->student->user->sort_name);
});
