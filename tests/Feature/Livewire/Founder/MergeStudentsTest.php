<?php

declare(strict_types=1);

use App\Enums\PhoneType;
use App\Enums\ShirtSize;
use App\Livewire\Founder\MergeStudents;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\Instrument;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Phone;
use App\Models\Recording;
use App\Models\Student;
use App\Models\User;
use App\Models\Version;
use App\Models\VoicePart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

function mergeStudentsPageStudent(string $first, string $last, ?string $cell = null, ?string $birthday = null): Student
{
    $user = User::factory()->create(['first_name' => $first, 'last_name' => $last, 'cell_phone' => $cell]);

    return Student::factory()->create(['user_id' => $user->id, 'birthday' => $birthday]);
}

/**
 * The seeder loads local roster data, so keep only pairs made of the given students.
 *
 * @param  list<Student>  $students
 * @return list<array<string, mixed>>
 */
function mergeStudentsPagePairs(array $students): array
{
    $ids = array_map(fn (Student $student): int => $student->id, $students);
    $pairs = Livewire::actingAs(makeFounderUser())->test(MergeStudents::class)->get('potentialDuplicates');

    return array_values(array_filter($pairs, fn (array $pair): bool => in_array($pair['winner_id'], $ids, true) && in_array($pair['loser_id'], $ids, true)));
}

test('students with different names but the same own cell are listed as a same-cell pair', function () {
    $winner = mergeStudentsPageStudent('Katherine', 'Doyle', '2015551234');
    $loser = mergeStudentsPageStudent('Katie', 'Doyle');
    Phone::factory()->create(['user_id' => $loser->user_id, 'type' => PhoneType::Cell, 'raw_number' => '2015551234']);

    $pairs = mergeStudentsPagePairs([$winner, $loser]);

    expect($pairs)->toHaveCount(1)
        ->and($pairs[0])->toMatchArray([
            'strength' => 'phone',
            'winner_id' => $winner->id,
            'loser_id' => $loser->id,
            'winner_name' => 'Katherine Doyle',
            'loser_name' => 'Katie Doyle',
            'shared_phone' => '(201) 555-1234',
        ]);
});

test('a same-name pair with different birthdays is strong when they share an own cell', function () {
    $winner = mergeStudentsPageStudent('Ava', 'Reyes', '2015551234', '2010-01-01');
    $loser = mergeStudentsPageStudent('Ava', 'Reyes', null, '2010-02-02');
    Phone::factory()->create(['user_id' => $loser->user_id, 'type' => PhoneType::Cell, 'raw_number' => '2015551234']);

    $pairs = mergeStudentsPagePairs([$winner, $loser]);

    expect($pairs)->toHaveCount(1)
        ->and($pairs[0]['strength'])->toBe('strong')
        ->and($pairs[0]['shared_phone'])->toBe('(201) 555-1234');
});

test('a same-name pair with different birthdays and no shared cell stays weak', function () {
    $winner = mergeStudentsPageStudent('Ava', 'Reyes', null, '2010-01-01');
    $loser = mergeStudentsPageStudent('Ava', 'Reyes', null, '2010-02-02');

    $pairs = mergeStudentsPagePairs([$winner, $loser]);

    expect($pairs)->toHaveCount(1)
        ->and($pairs[0]['strength'])->toBe('weak')
        ->and($pairs[0]['shared_phone'])->toBeNull();
});

test('shared home phones and emergency contact numbers do not pair students', function () {
    $a = mergeStudentsPageStudent('Liam', 'Park');
    $b = mergeStudentsPageStudent('Noah', 'Park');
    Phone::factory()->create(['user_id' => $a->user_id, 'type' => PhoneType::Home, 'raw_number' => '2015557777']);
    Phone::factory()->create(['user_id' => $b->user_id, 'type' => PhoneType::Home, 'raw_number' => '2015557777']);
    EmergencyContact::factory()->create(['student_id' => $a->id, 'cell_phone' => '2015558888']);
    EmergencyContact::factory()->create(['student_id' => $b->id, 'cell_phone' => '2015558888']);

    expect(mergeStudentsPagePairs([$a, $b]))->toBe([]);
});

test('pairs are ordered strong, then same cell, then weak, and the list shows the shared number', function () {
    $students = [
        mergeStudentsPageStudent('Ann', 'Baker', null, '2010-01-01'),
        mergeStudentsPageStudent('Ann', 'Baker', null, '2011-01-01'),
        mergeStudentsPageStudent('Ben', 'Cole', '2015551234'),
        $benny = mergeStudentsPageStudent('Benny', 'Cole'),
        mergeStudentsPageStudent('Zoe', 'Adams', null, '2010-01-01'),
        mergeStudentsPageStudent('Zoe', 'Adams', null, '2010-01-01'),
    ];
    Phone::factory()->create(['user_id' => $benny->user_id, 'type' => PhoneType::Cell, 'raw_number' => '2015551234']);

    expect(array_column(mergeStudentsPagePairs($students), 'strength'))->toBe(['strong', 'phone', 'weak']);

    Livewire::actingAs(makeFounderUser())->test(MergeStudents::class)->assertSee('Same cell: (201) 555-1234');
});

test('the founder page shows default voice parts and warns about voice part and same-version candidate differences', function () {
    $soprano = VoicePart::factory()->create(['name' => 'Soprano I']);
    $alto = VoicePart::factory()->create(['name' => 'Alto II']);
    $winner = Student::factory()->create(['voice_part_id' => $soprano->id, 'birthday' => null, 'height' => 60, 'shirt_size' => ShirtSize::MED]);
    $loser = Student::factory()->create(['voice_part_id' => $alto->id, 'birthday' => null, 'height' => 64, 'shirt_size' => ShirtSize::XL]);
    $version = Version::factory()->create(['name' => 'All-State Chorus 2027']);
    Candidate::factory()->create(['student_id' => $winner->id, 'version_id' => $version->id, 'voice_part_id' => $soprano->id]);
    Candidate::factory()->registered()->create(['student_id' => $loser->id, 'version_id' => $version->id, 'voice_part_id' => $alto->id]);

    Livewire::actingAs(makeFounderUser())
        ->test(MergeStudents::class)
        ->call('preselectPair', $winner->id, $loser->id)
        ->assertSee('Profile differences')
        ->assertSee('keeps Soprano I, not Alto II.', false)
        ->assertSee('keeps 60&quot;, not 64&quot;.', false)
        ->assertSee('keeps Medium, not X-Large.', false)
        ->assertSee('keeps Soprano I, not Alto II; keeps status Eligible, not Registered.', false);
});

test('the founder page shows profile fields and no warnings when nothing differs', function () {
    $alto = VoicePart::factory()->create(['name' => 'Alto II']);
    $flute = Instrument::factory()->create(['name' => 'Flute']);
    $winner = Student::factory()->create(['voice_part_id' => $alto->id, 'instrument_id' => $flute->id, 'birthday' => '2010-01-01', 'height' => 62, 'shirt_size' => ShirtSize::LG]);
    $loser = Student::factory()->create(['voice_part_id' => null, 'birthday' => null, 'height' => null, 'shirt_size' => ShirtSize::LG]);

    Livewire::actingAs(makeFounderUser())
        ->test(MergeStudents::class)
        ->call('preselectPair', $winner->id, $loser->id)
        ->assertSee('Alto II')
        ->assertSee('Flute')
        ->assertSee('62&quot;', false)
        ->assertSee('Large')
        ->assertDontSee('Profile differences')
        ->assertDontSee('Both are Candidates in the same Version');
});

test('merge search ranks exact last-name matches ahead of names that merely contain the term', function () {
    // Twelve "contains" matches that sort alphabetically ahead of the exact one —
    // the old alphabetical limit(10) never reached the real Quolee.
    foreach (range(1, 12) as $i) {
        mergeStudentsPageStudent('Aquolee'.$i, 'Abbott');
    }
    $exact = mergeStudentsPageStudent('Zian', 'Quolee');

    $results = Livewire::actingAs(makeFounderUser())
        ->test(MergeStudents::class)
        ->set('winnerSearch', 'quolee')
        ->viewData('winnerResults');

    expect($results)->toHaveCount(10)
        ->and($results->first()->id)->toBe($exact->id);
});

test('merge search says how many matches were left out', function () {
    foreach (range(1, 12) as $i) {
        mergeStudentsPageStudent('Xyvren'.$i, 'Abbott');
    }

    Livewire::actingAs(makeFounderUser())
        ->test(MergeStudents::class)
        ->set('winnerSearch', 'xyvren')
        ->assertSee('Showing best 10 of 12 matches');
});

test('merge search requires every term to match, so a full name narrows to that student', function () {
    $ian = mergeStudentsPageStudent('Zianth', 'Quolee');
    $other = mergeStudentsPageStudent('Zianth', 'Morrow');
    $sibling = mergeStudentsPageStudent('Brev', 'Quolee');

    $ids = Livewire::actingAs(makeFounderUser())
        ->test(MergeStudents::class)
        ->set('winnerSearch', 'Zianth Quolee')
        ->viewData('winnerResults')
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$ian->id]);
});

test('merge search matches a middle name and an email fragment', function () {
    $student = mergeStudentsPageStudent('Zianth', 'Quolee');
    $student->user->update(['middle_name' => 'Jorvik', 'email' => 'zq.unique@htps-example.us']);

    $component = Livewire::actingAs(makeFounderUser())->test(MergeStudents::class);

    expect($component->set('winnerSearch', 'Zianth Jorvik')->viewData('winnerResults')->pluck('id')->all())->toBe([$student->id])
        ->and($component->set('winnerSearch', 'htps-example')->viewData('winnerResults')->pluck('id')->all())->toBe([$student->id]);
});

test('merge search finds a student by student id or user id', function () {
    $student = mergeStudentsPageStudent('Zianth', 'Quolee');

    $component = Livewire::actingAs(makeFounderUser())->test(MergeStudents::class);

    expect($component->set('winnerSearch', (string) $student->id)->viewData('winnerResults')->pluck('id')->all())->toContain($student->id)
        ->and($component->set('winnerSearch', (string) $student->user_id)->viewData('winnerResults')->pluck('id')->all())->toContain($student->id);
});

test('merge search treats percent and underscore literally', function () {
    mergeStudentsPageStudent('Zianth', 'Quolee');

    Livewire::actingAs(makeFounderUser())
        ->test(MergeStudents::class)
        ->set('winnerSearch', '%_')
        ->assertViewHas('winnerResults', fn ($results) => $results->isEmpty());
});

test('merge search finds a single-digit student id but still ignores a single letter', function () {
    $student = mergeStudentsPageStudent('Zianth', 'Quolee');
    DB::table('students')->where('id', $student->id)->update(['id' => 7]);

    $component = Livewire::actingAs(makeFounderUser())->test(MergeStudents::class);

    expect($component->set('winnerSearch', '7')->viewData('winnerResults')->pluck('id')->all())->toBe([7])
        ->and($component->set('winnerSearch', 'Z')->viewData('winnerResults')->all())->toBe([]);
});
