<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\FeeType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\VersionDateType;
use App\Enums\VersionInvitationStatus;
use App\Mail\DirectorWeeklyUpdateMail;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionFee;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use App\Services\DirectorWeeklyUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    // CandidateObserver writes candidate_status_history rows keyed by Auth::id().
    actingAs(User::factory()->create());
});

function inviteDirectorForWeeklyUpdate(Version $version, VersionInvitationStatus $status): VersionInvitation
{
    return VersionInvitation::create([
        'version_id' => $version->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'status' => $status->value,
        'invited_at' => now(),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

test('emails invited, obligated, and participating directors of an active version, but not rejected ones', function () {
    Mail::fake();

    $version = Version::factory()->active()->create();
    $invited = inviteDirectorForWeeklyUpdate($version, VersionInvitationStatus::Invited);
    $obligated = inviteDirectorForWeeklyUpdate($version, VersionInvitationStatus::Obligated);
    $participating = inviteDirectorForWeeklyUpdate($version, VersionInvitationStatus::Participating);
    $rejected = inviteDirectorForWeeklyUpdate($version, VersionInvitationStatus::Rejected);

    artisan('versions:send-director-weekly-update')->assertSuccessful();

    foreach ([$invited, $obligated, $participating] as $invitation) {
        Mail::assertSent(DirectorWeeklyUpdateMail::class, fn ($mail) => $mail->hasTo($invitation->teacher->user->email));
    }
    Mail::assertNotSent(DirectorWeeklyUpdateMail::class, fn ($mail) => $mail->hasTo($rejected->teacher->user->email));
});

test('does not email directors of a non-active version', function () {
    Mail::fake();

    $version = Version::factory()->create(['status' => EventStatus::Sandbox]);
    inviteDirectorForWeeklyUpdate($version, VersionInvitationStatus::Invited);

    artisan('versions:send-director-weekly-update')->assertSuccessful();

    Mail::assertNothingSent();
});

test('builds counts, fees, dates, and rosters from the director\'s own candidates', function () {
    $version = Version::factory()->active()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2000, 'participation' => 5000]);
    VersionDate::create(['version_id' => $version->id, 'date_type' => VersionDateType::Adjudication, 'start_at' => now()->addMonth(), 'end_at' => now()->addMonth()->addDay()]);
    VersionDate::create(['version_id' => $version->id, 'date_type' => VersionDateType::Candidate, 'start_at' => now()->subWeek(), 'end_at' => now()->addWeeks(2)]);
    VersionDate::create(['version_id' => $version->id, 'date_type' => VersionDateType::Teacher, 'start_at' => now()->subWeek()]);

    $invitation = inviteDirectorForWeeklyUpdate($version, VersionInvitationStatus::Obligated);
    $voicePart = VoicePart::factory()->create(['name' => 'Soprano I']);

    $registered = Candidate::factory()->count(2)->create([
        'version_id' => $version->id,
        'teacher_id' => $invitation->teacher_id,
        'voice_part_id' => $voicePart->id,
        'status' => CandidateStatus::Registered,
    ]);

    // Missing the Version's required emergency contact.
    $missingContact = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $invitation->teacher_id,
        'status' => CandidateStatus::Pending,
    ]);

    // Fully checklisted but still Pending — only the audition cap does that.
    $capHeld = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $invitation->teacher_id,
        'status' => CandidateStatus::Pending,
    ]);
    EmergencyContact::factory()->create(['student_id' => $capHeld->student_id, 'cell_phone' => '5555551234']);

    // Another director's candidate and payment must not leak in.
    $otherCandidate = Candidate::factory()->create(['version_id' => $version->id, 'status' => CandidateStatus::Registered]);

    foreach ([[$registered[0], 2000], [$otherCandidate, 2000]] as [$candidate, $amount]) {
        $transaction = PaymentTransaction::factory()->create([
            'version_id' => $version->id,
            'amount' => $amount,
            'status' => PaymentTransactionStatus::Completed,
            'fee_type' => FeeType::Registration,
        ]);
        PaymentAllocation::factory()->create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $candidate->id, 'amount' => $amount]);
    }

    $update = app(DirectorWeeklyUpdateService::class)->updateFor($invitation->fresh());

    expect($update->invitationStatus)->toBe(VersionInvitationStatus::Obligated)
        ->and($update->pendingStudents)->toBe(2)
        ->and($update->registeredStudents)->toBe(2)
        ->and($update->registrationFeeCents)->toBe(2000)
        ->and($update->participationFeeCents)->toBe(5000)
        ->and($update->dueCents)->toBe(4000)
        ->and($update->paidCents)->toBe(2000)
        ->and($update->balanceDueCents())->toBe(2000)
        ->and(collect($update->dates)->map(fn (VersionDate $d) => $d->date_type)->all())
        ->toBe([VersionDateType::Candidate, VersionDateType::Adjudication])
        ->and(collect($update->registeredRoster)->pluck('voicePart')->unique()->all())->toBe(['Soprano I']);

    $missingByName = collect($update->pendingRoster)->pluck('missing', 'name');
    expect($missingByName[$missingContact->student->user->name])->toBe(['Emergency contact'])
        ->and($missingByName[$capHeld->student->user->name])->toBe([DirectorWeeklyUpdateService::AUDITION_CAP_HOLD]);

    $html = (new DirectorWeeklyUpdateMail($update))->render();
    expect($html)->toContain('Obligated')
        ->toContain('Some value of the student')
        ->toContain('All required values for registration have been satisfied')
        ->toContain('$40.00')
        ->toContain('$50.00')
        ->toContain($missingContact->student->user->name)
        ->toContain(' ET</td>')
        ->and(preg_match('/\bE[DS]T\b/', $html))->toBe(0);
});

test('shows an overpayment as a credit rather than a negative balance due', function () {
    $version = Version::factory()->active()->create();
    VersionFee::create(['version_id' => $version->id, 'registration' => 2800]);
    $invitation = inviteDirectorForWeeklyUpdate($version, VersionInvitationStatus::Obligated);

    // Paid ahead for a candidate who hasn't reached Registered yet.
    $candidate = Candidate::factory()->create([
        'version_id' => $version->id,
        'teacher_id' => $invitation->teacher_id,
        'status' => CandidateStatus::Pending,
    ]);
    $transaction = PaymentTransaction::factory()->create([
        'version_id' => $version->id,
        'amount' => 2800,
        'status' => PaymentTransactionStatus::Completed,
        'fee_type' => FeeType::Registration,
    ]);
    PaymentAllocation::factory()->create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $candidate->id, 'amount' => 2800]);

    $update = app(DirectorWeeklyUpdateService::class)->updateFor($invitation->fresh());
    $html = (new DirectorWeeklyUpdateMail($update))->render();

    expect($update->balanceDueCents())->toBe(-2800)
        ->and($html)->toContain('Credit')
        ->and(str_contains($html, 'Balance Due'))->toBeFalse()
        ->and(str_contains($html, '-$28.00'))->toBeFalse();
});

test('hides the fees section when the version charges no fees', function () {
    $version = Version::factory()->active()->create();
    $invitation = inviteDirectorForWeeklyUpdate($version, VersionInvitationStatus::Invited);

    $update = app(DirectorWeeklyUpdateService::class)->updateFor($invitation->fresh());

    expect($update->hasFees())->toBeFalse()
        ->and((new DirectorWeeklyUpdateMail($update))->render())->not->toContain('Balance Due');
});
