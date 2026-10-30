<?php

declare(strict_types=1);

use App\Livewire\Founder\MergeStudents;
use App\Models\Candidate;
use App\Models\Student;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('merging moves the loser candidate to the winner instead of cascade-deleting it', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $candidate = Candidate::factory()->create(['student_id' => $loser->id]);

    actingAs(makeFounderUser());

    Livewire::test(MergeStudents::class)
        ->call('preselectPair', $winner->id, $loser->id)
        ->call('merge');

    expect(Student::find($loser->id))->toBeNull()
        ->and($candidate->fresh()?->student_id)->toBe($winner->id);
});

test('merging is refused when both students have a candidate in the same version', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create();
    Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $version->id]);
    $loserCandidate = Candidate::factory()->create(['student_id' => $loser->id, 'version_id' => $version->id]);

    actingAs(makeFounderUser());

    Livewire::test(MergeStudents::class)
        ->call('preselectPair', $winner->id, $loser->id)
        ->call('merge')
        ->assertSet('loserId', $loser->id);

    expect(Student::find($loser->id))->not->toBeNull()
        ->and($loserCandidate->fresh()?->student_id)->toBe($loser->id);
});
