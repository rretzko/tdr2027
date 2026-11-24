<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Livewire\Events\VersionRegistrationHistory;
use App\Livewire\Events\WebRegistration;
use App\Models\Candidate;
use App\Models\CandidateStatusHistory;
use App\Models\CandidateUploadFile;
use App\Models\CoRegistrationManagerCounty;
use App\Models\County;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionInvitation;
use App\Models\VersionUploadFile;
use App\Services\RegistrationHistoryService;
use App\Services\RegistrationHistoryTextExporter;
use App\Support\RegistrationHistory\HistoryEntry;
use App\Support\RegistrationHistory\RegistrationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

uses(RefreshDatabase::class);

function regHistoryCandidate(Version $version, string $first, string $last, ?County $county = null, ?Teacher $teacher = null): Candidate
{
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => ($teacher ?? Teacher::factory()->create())->id,
        'school_id' => School::factory()->create($county !== null ? ['county_id' => $county->id] : [])->id,
    ]);
    $candidate->student->user->update(['first_name' => $first, 'last_name' => $last, 'name' => "{$first} {$last}"]);

    return $candidate;
}

function regHistoryManager(Version $version, string $role): User
{
    $user = User::factory()->create();
    grantVersionRole($user, $version, $role);

    return $user;
}

/** The database, not Carbon, stamps status history, so time travel misses it. */
function regHistoryStampStatus(Candidate $candidate): void
{
    CandidateStatusHistory::query()->where('candidate_id', $candidate->id)->latest('id')->limit(1)->update(['created_at' => now()]);
}

/** @return list<string> */
function regHistoryLines(RegistrationHistory $history): array
{
    return array_values($history->entries->map(fn (HistoryEntry $e): string => $e->description)->all());
}

test('every registration role is allowed and anyone else gets 403', function () {
    $version = Version::factory()->create();

    foreach (['Event Manager', 'Registration Manager', 'Co-Registration Manager', 'Web Registration Manager'] as $role) {
        Livewire::actingAs(regHistoryManager($version, $role))
            ->test(VersionRegistrationHistory::class, ['version' => $version])
            ->assertStatus(200);
    }

    Livewire::actingAs(regHistoryManager($version, 'Tab Room Manager'))
        ->test(VersionRegistrationHistory::class, ['version' => $version])
        ->assertStatus(403);
});

test('a Registration Manager sees the Registration History tab', function () {
    $version = Version::factory()->create();

    Livewire::actingAs(regHistoryManager($version, 'Registration Manager'))
        ->test(WebRegistration::class, ['version' => $version])
        ->assertSee('Registration History')
        ->assertDontSee('Impersonate Teacher');
});

test('student and teacher searches are separate, scoped to the Version and clear each other', function () {
    $version = Version::factory()->create();
    regHistoryCandidate($version, 'Marie ', 'Findable');
    regHistoryCandidate(Version::factory()->create(), 'Elsewhere', 'Findable');
    $invited = Teacher::factory()->create();
    $invited->user->update(['first_name' => 'Sam', 'last_name' => 'Inviteonly', 'name' => 'Sam Inviteonly']);
    VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => $invited->id,
        'status' => 'invited',
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);

    Livewire::actingAs(makeFounder())
        ->test(VersionRegistrationHistory::class, ['version' => $version])
        ->set('studentSearch', 'f')
        ->assertDontSee('Marie')
        ->set('studentSearch', 'Findable Marie')
        ->assertSee('Marie')
        ->assertDontSee('Elsewhere')
        ->set('teacherSearch', 'inviteonly')
        ->assertSet('studentSearch', '')
        ->assertSee('Sam Inviteonly')
        ->call('selectTeacher', $invited->id)
        ->assertSee('Teacher invited')
        ->set('studentSearch', '%%')
        ->assertSet('teacherId', null)
        ->assertSet('teacherSearch', '')
        ->assertSee('No student matches that name.');
});

test('ids from another Version are refused', function () {
    $version = Version::factory()->create();
    $other = regHistoryCandidate(Version::factory()->create(), 'Else', 'Where');

    Livewire::actingAs(makeFounder())
        ->test(VersionRegistrationHistory::class, ['version' => $version])
        ->call('selectStudent', $other->id)
        ->assertStatus(404);

    Livewire::actingAs(makeFounder())
        ->test(VersionRegistrationHistory::class, ['version' => $version])
        ->call('selectTeacher', $other->teacher_id)
        ->assertStatus(404);
});

test('the registration history selection cannot be set from the browser', function () {
    Livewire::actingAs(makeFounder())
        ->test(VersionRegistrationHistory::class, ['version' => Version::factory()->create()])
        ->set('candidateId', 1);
})->throws(CannotUpdateLockedPropertyException::class);

test('a Co-Registration Manager is limited to their counties, including shared payments', function () {
    $version = Version::factory()->create();
    $countyA = County::factory()->create();
    $countyB = County::factory()->create();
    $shared = Teacher::factory()->create();
    $inA = regHistoryCandidate($version, 'Ina', 'Scoped', $countyA, $shared);
    $inB = regHistoryCandidate($version, 'Inb', 'Scoped', $countyB, $shared);
    $onlyB = regHistoryCandidate($version, 'Onlyb', 'Scoped', $countyB);

    // One group payment split across both counties.
    $payment = PaymentTransaction::factory()->teacherEpayment()->completed()->create([
        'version_id' => $version->id, 'payer_teacher_id' => $shared->id, 'amount' => 5000,
    ]);
    foreach ([[$inA, 2000], [$inB, 3000]] as [$candidate, $cents]) {
        PaymentAllocation::factory()->create([
            'payment_transaction_id' => $payment->id, 'candidate_id' => $candidate->id, 'amount' => $cents, 'allocated_at' => now(),
        ]);
    }

    $manager = regHistoryManager($version, 'Co-Registration Manager');
    CoRegistrationManagerCounty::create(['version_id' => $version->id, 'user_id' => $manager->id, 'county_id' => $countyA->id]);

    Livewire::actingAs($manager)
        ->test(VersionRegistrationHistory::class, ['version' => $version])
        ->set('studentSearch', 'Scoped')
        ->assertSee($inA->ref)
        ->assertDontSee($inB->ref)
        ->call('selectTeacher', $shared->id)
        ->assertSee($inA->ref)
        ->assertDontSee($inB->ref)
        ->assertSee('$20.00')
        ->assertDontSee('$50.00')
        ->assertDontSee('$30.00');

    Livewire::actingAs($manager)
        ->test(VersionRegistrationHistory::class, ['version' => $version])
        ->call('selectStudent', $inB->id)
        ->assertStatus(404);

    Livewire::actingAs($manager)
        ->test(VersionRegistrationHistory::class, ['version' => $version])
        ->call('selectTeacher', $onlyB->teacher_id)
        ->assertStatus(404);

    // Everyone unrestricted sees the whole payment.
    Livewire::actingAs(regHistoryManager($version, 'Web Registration Manager'))
        ->test(VersionRegistrationHistory::class, ['version' => $version])
        ->call('selectTeacher', $shared->id)
        ->assertSee($inB->ref)
        ->assertSee('$50.00');
});

test('a student history lists everything oldest first with the checklist', function () {
    travelTo(CarbonImmutable::parse('2026-11-12 17:00:00', 'UTC'));
    $version = Version::factory()->create(['audition_type' => 'remote', 'upload_type' => 'audio']);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'scales', 'order_by' => 1]);
    VersionDate::create(['version_id' => $version->id, 'date_type' => 'final_teacher_changes', 'start_at' => '2026-11-13 10:00:00']);
    VersionDate::create(['version_id' => $version->id, 'date_type' => 'admin', 'start_at' => '2026-11-01 10:00:00']);
    $candidate = regHistoryCandidate($version, 'Marie', 'Ordered');
    regHistoryStampStatus($candidate);
    $fee = FeeType::Registration->label();

    travel(1)->hours();
    $candidate->update(['status' => CandidateStatus::Pending->value]);
    regHistoryStampStatus($candidate);

    travel(1)->hours();
    CandidateUploadFile::create([
        'candidate_id' => $candidate->id, 'version_upload_file_id' => $slot->id, 'url' => 'secret/path.mp3',
        'original_filename' => 'Marie.mp3', 'status' => 'pending', 'uploaded_at' => now(),
    ]);

    travel(1)->hours();
    $expired = PaymentTransaction::factory()->candidateEpayment()->create([
        'version_id' => $version->id, 'status' => PaymentTransactionStatus::Expired, 'amount' => 2000, 'fee_type' => 'registration',
    ]);
    PaymentAllocation::factory()->create(['payment_transaction_id' => $expired->id, 'candidate_id' => $candidate->id, 'amount' => 2000, 'allocated_at' => now()]);

    travel(1)->hours();
    $check = PaymentTransaction::factory()->create([
        'version_id' => $version->id, 'source' => PaymentSource::Manual, 'payment_type' => PaymentType::Check,
        'amount' => 2000, 'fee_type' => 'registration', 'paid_at' => '2026-11-12 00:00:00',
    ]);
    PaymentAllocation::factory()->create(['payment_transaction_id' => $check->id, 'candidate_id' => $candidate->id, 'amount' => 2000, 'allocated_at' => now()]);

    DB::table('login_events')->insert([
        ['user_id' => $candidate->student->user_id, 'method' => 'email', 'created_at' => '2026-10-01 00:00:00'],
        ['user_id' => $candidate->student->user_id, 'method' => 'email', 'created_at' => '2026-11-12 23:00:00'],
    ]);
    travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));

    $history = app(RegistrationHistoryService::class)->forCandidate(Candidate::findOrFail($candidate->id));

    expect(regHistoryLines($history))->toBe([
        'Student account created',
        'Candidate created (Eligible)',
        'Status changed: Eligible → Pending',
        'Recording uploaded: scales (Marie.mp3)',
        "E-payment checkout started: {$fee}, \$20.00 (now Expired)",
        "Payment recorded: {$fee}, \$20.00 Check, dated Nov 12, 2026",
        'Logged in (email)',
        'Final Teacher Changes deadline',
    ]);

    $done = collect($history->checklist)->pluck('done', 'label');
    expect($done['Upload of required audio files'])->toBeTrue();
    expect($done['Teacher approval of required audio files'])->toBeFalse();
});

test('outcomes stay hidden until results are released, then only the final one shows', function () {
    $version = Version::factory()->create(['results_released_at' => null]);
    $candidate = regHistoryCandidate($version, 'Out', 'Come');
    foreach (['registered', 'accepted', 'registered', 'not_accepted'] as $status) {
        $candidate->update(['status' => $status]);
    }

    $service = app(RegistrationHistoryService::class);
    $before = $service->forCandidate(Candidate::findOrFail($candidate->id));
    expect($before->facts['Status'])->toBe('Registered');
    expect(str_contains(implode('|', regHistoryLines($before)), 'Accepted'))->toBeFalse();

    Livewire::actingAs(makeFounder())
        ->test(VersionRegistrationHistory::class, ['version' => $version])
        ->set('studentSearch', 'Come')
        ->assertSee('Registered')
        ->assertDontSee('Not Accepted');

    $version->update(['results_released_at' => now()->subMinute()]);
    $after = regHistoryLines($service->forCandidate(Candidate::findOrFail($candidate->id)));
    expect(array_values(array_filter($after, fn (string $l): bool => str_contains($l, 'Accepted'))))->toBe(['Outcome: Not Accepted']);
});

test('a teacher history names students, lists incomplete ones and does not add queries per student', function () {
    $version = Version::factory()->create();
    $teacher = Teacher::factory()->create();
    $service = app(RegistrationHistoryService::class);
    $count = function () use ($version, $teacher, $service): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->forTeacher(Version::findOrFail($version->id), Teacher::findOrFail($teacher->id), null);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    regHistoryCandidate($version, 'Anna', 'Roster', null, $teacher);
    regHistoryCandidate($version, 'Beth', 'Roster', null, $teacher);
    $two = $count();
    foreach (['Cara', 'Dana', 'Elle', 'Fran'] as $first) {
        regHistoryCandidate($version, $first, 'Roster', null, $teacher);
    }

    expect($count())->toBe($two);

    $history = $service->forTeacher(Version::findOrFail($version->id), Teacher::findOrFail($teacher->id), null);
    expect($history->incomplete)->toHaveCount(6);
    expect($history->facts['Students'])->toBe('6');
    expect($history->entries->pluck('student')->filter()->implode('|'))->toContain('Anna Roster');
});

test('a candidate with no creation history still gets a created line', function () {
    $candidate = regHistoryCandidate(Version::factory()->create(), 'Legacy', 'Import');
    CandidateStatusHistory::query()->delete();

    expect(regHistoryLines(app(RegistrationHistoryService::class)->forCandidate(Candidate::findOrFail($candidate->id))))
        ->toContain('Candidate record created');
});

test('the .txt download matches the history in Eastern time, one line per entry', function () {
    travelTo(CarbonImmutable::parse('2026-11-12 17:31:40', 'UTC'));
    $version = Version::factory()->create(['name' => 'Honor Choir 2027']);
    $candidate = regHistoryCandidate($version, 'Marie', 'Balsamo');
    regHistoryStampStatus($candidate);
    $slot = VersionUploadFile::create(['version_id' => $version->id, 'name' => 'scales', 'order_by' => 1]);
    CandidateUploadFile::create([
        'candidate_id' => $candidate->id, 'version_upload_file_id' => $slot->id, 'url' => 'secret/path.mp3',
        'original_filename' => "bad\r\nname.mp3", 'status' => 'pending', 'uploaded_at' => now(),
    ]);

    Livewire::actingAs(makeFounder())
        ->test(VersionRegistrationHistory::class, ['version' => $version])
        ->call('selectStudent', $candidate->id)
        ->call('download')
        ->assertFileDownloaded("registration-history-v{$version->id}-balsamo-marie-20261112.txt");

    $text = app(RegistrationHistoryTextExporter::class)->render(
        app(RegistrationHistoryService::class)->forCandidate(Candidate::findOrFail($candidate->id)),
        now(),
    );

    expect($text)->toContain('REGISTRATION HISTORY - Honor Choir 2027')
        ->toContain('2026-11-12 12:31:40 PM  Candidate created (Eligible)')
        ->toContain('Recording uploaded: scales (bad name.mp3)')
        ->toContain('REGISTRATION CHECKLIST');
    expect(str_contains($text, 'secret/path'))->toBeFalse();
});

test('download with nothing selected is refused', function () {
    Livewire::actingAs(makeFounder())
        ->test(VersionRegistrationHistory::class, ['version' => Version::factory()->create()])
        ->call('download')
        ->assertStatus(404);
});
