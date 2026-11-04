<?php

declare(strict_types=1);

use App\Livewire\Founder\MergeStudents;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Recording;
use App\Models\Student;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('merging on the founder page keeps the loser candidate and its payment on the winner', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create();
    $candidate = Candidate::factory()->create(['student_id' => $loser->id, 'version_id' => $version->id]);
    $transaction = PaymentTransaction::factory()->create(['version_id' => $version->id, 'payer_student_id' => $loser->id, 'amount' => 2800]);
    PaymentAllocation::factory()->create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $candidate->id, 'amount' => 2800]);

    Livewire::actingAs(makeFounderUser())
        ->test(MergeStudents::class)
        ->call('preselectPair', $winner->id, $loser->id)
        ->call('merge');

    expect($candidate->fresh()?->student_id)->toBe($winner->id)
        ->and((int) PaymentAllocation::where('candidate_id', $candidate->id)->sum('amount'))->toBe(2800)
        ->and($transaction->fresh()?->payer_student_id)->toBe($winner->id)
        ->and(Student::find($loser->id))->toBeNull();
});

test('the founder page refuses a merge that would delete adjudication data and keeps both students', function () {
    $winner = Student::factory()->create();
    $loser = Student::factory()->create();
    $version = Version::factory()->create(['name' => 'Region II Chorus 2027']);
    Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $version->id]);
    $loserCandidate = Candidate::factory()->create(['student_id' => $loser->id, 'version_id' => $version->id]);
    Recording::create([
        'version_id' => $version->id,
        'candidate_id' => $loserCandidate->id,
        'file_type' => 'Solo',
        'uploaded_by' => User::factory()->create()->id,
        'url' => 'recordings/solo.mp3',
    ]);

    Livewire::actingAs(makeFounderUser())
        ->test(MergeStudents::class)
        ->call('preselectPair', $winner->id, $loser->id)
        ->call('merge')
        ->assertSet('loserId', $loser->id);

    expect(Student::find($loser->id))->not->toBeNull()
        ->and($loserCandidate->fresh()?->student_id)->toBe($loser->id);
});

test('the founder page shows both students phones and warns about a number the merge would delete', function () {
    $winner = Student::factory()->create(['user_id' => User::factory()->create(['cell_phone' => '2015550000'])->id]);
    $loser = Student::factory()->create(['user_id' => User::factory()->create(['cell_phone' => '2015551234'])->id]);

    Livewire::actingAs(makeFounderUser())
        ->test(MergeStudents::class)
        ->call('preselectPair', $winner->id, $loser->id)
        ->assertSee('(201) 555-0000')
        ->assertSee('these loser phone numbers will be deleted: (201) 555-1234', false);
});

test('the founder page moves the loser cell phone onto a winner without one', function () {
    $winner = Student::factory()->create(['user_id' => User::factory()->create(['cell_phone' => null])->id]);
    $loser = Student::factory()->create(['user_id' => User::factory()->create(['cell_phone' => '2015551234'])->id]);

    Livewire::actingAs(makeFounderUser())
        ->test(MergeStudents::class)
        ->call('preselectPair', $winner->id, $loser->id)
        ->assertDontSee('these loser phone numbers will be deleted')
        ->call('merge');

    expect($winner->user->fresh()?->cell_phone)->toBe('2015551234');
});
