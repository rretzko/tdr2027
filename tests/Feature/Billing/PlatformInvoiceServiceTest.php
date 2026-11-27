<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\PlatformInvoiceSendOutcome;
use App\Enums\PlatformInvoiceStatus;
use App\Mail\PlatformInvoiceMail;
use App\Models\Candidate;
use App\Models\CandidateFeeWaiver;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionPlatformInvoice;
use App\Services\PlatformInvoiceService;
use App\Support\Billing\PlatformInvoiceData;
use App\Support\Billing\PlatformInvoicePdf;
use App\Support\Billing\StorageCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

function platformInvoiceVersion(array $attributes = [], array $eventAttributes = []): Version
{
    $event = Event::factory()->create([
        'organization_id' => Organization::factory()->create(['name' => 'Test Music Educators'])->id,
        ...$eventAttributes,
    ]);

    return Version::factory()->create(['event_id' => $event->id, 'status' => 'closed', ...$attributes]);
}

function platformInvoiceRegistrants(Version $version, int $count, CandidateStatus $status = CandidateStatus::Accepted): void
{
    Candidate::factory()->count($count)->create(['version_id' => $version->id, 'status' => $status]);
}

function platformInvoiceManager(Version $version, string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    grantVersionRole($user, $version, 'Event Manager');

    return $user;
}

function platformInvoiceService(): PlatformInvoiceService
{
    return app(PlatformInvoiceService::class);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 15:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('scheduleOnClose creates a pending invoice due to send 24 hours later', function () {
    $version = platformInvoiceVersion();

    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    expect($invoice->statusEnum())->toBe(PlatformInvoiceStatus::Pending);
    expect($invoice->send_at->equalTo(Carbon::now()->addHours(24)))->toBeTrue();
    expect($invoice->number)->toBeNull();
});

test('the command does not send before 24 hours and sends after', function () {
    Mail::fake();
    $version = platformInvoiceVersion();
    platformInvoiceRegistrants($version, 4);
    platformInvoiceManager($version, 'em@example.com');
    platformInvoiceService()->scheduleOnClose($version, null);

    Carbon::setTestNow(Carbon::now()->addHours(23)->addMinutes(59));
    artisan('invoices:send-due')->assertSuccessful();
    Mail::assertNothingSent();

    Carbon::setTestNow(Carbon::now()->addMinutes(2));
    artisan('invoices:send-due')->assertSuccessful();

    Mail::assertSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->hasTo('em@example.com')
        && $mail->hasBcc('rick@mfrholdings.com')
        && $mail->variant === PlatformInvoiceMail::VARIANT_INVOICE);

    $invoice = VersionPlatformInvoice::where('version_id', $version->id)->sole();
    expect($invoice->statusEnum())->toBe(PlatformInvoiceStatus::Sent);
    expect($invoice->registrant_count)->toBe(4);
    expect($invoice->rate_cents)->toBe(275);
    expect($invoice->amount_cents)->toBe(1100);
    expect($invoice->recipients)->toBe(['em@example.com']);
});

test('the registrant count uses every registrant state, counts fee-waived candidates, and skips non-registrants', function () {
    $version = platformInvoiceVersion();
    platformInvoiceRegistrants($version, 2, CandidateStatus::Accepted);
    platformInvoiceRegistrants($version, 1, CandidateStatus::NotAccepted);
    platformInvoiceRegistrants($version, 1, CandidateStatus::NoShow);
    platformInvoiceRegistrants($version, 1, CandidateStatus::Registered);
    platformInvoiceRegistrants($version, 3, CandidateStatus::Eligible);
    platformInvoiceRegistrants($version, 2, CandidateStatus::Withdrew);

    $waived = Candidate::where('version_id', $version->id)->where('status', CandidateStatus::Accepted)->first();
    CandidateFeeWaiver::factory()->create(['candidate_id' => $waived->id]);

    expect(platformInvoiceService()->registrantCount($version))->toBe(5);
});

test('an Event rate override wins over the config default', function () {
    $version = platformInvoiceVersion([], ['platform_fee_per_registrant_cents' => 300]);

    expect(platformInvoiceService()->rateCents($version->fresh()))->toBe(300);

    $default = platformInvoiceVersion();
    expect(platformInvoiceService()->rateCents($default))->toBe(275);
});

test('recipients are this Version\'s Event Managers only, not a sibling Version\'s', function () {
    Mail::fake();
    $version = platformInvoiceVersion();
    $sibling = Version::factory()->create(['event_id' => $version->event_id, 'status' => 'active']);
    platformInvoiceRegistrants($version, 2);
    platformInvoiceManager($version, 'this-em@example.com');
    platformInvoiceManager($sibling, 'sibling-em@example.com');

    $invoice = platformInvoiceService()->scheduleOnClose($version, null);
    platformInvoiceService()->send($invoice);

    Mail::assertSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->hasTo('this-em@example.com')
        && ! $mail->hasTo('sibling-em@example.com'));
});

test('invoices are numbered TDR-{year}-NNNN in sequence and reset in a new year', function () {
    Mail::fake();
    $numbers = [];

    foreach ([1, 2] as $i) {
        $version = platformInvoiceVersion();
        platformInvoiceRegistrants($version, 1);
        platformInvoiceManager($version, "em{$i}@example.com");
        $invoice = platformInvoiceService()->scheduleOnClose($version, null);
        platformInvoiceService()->send($invoice);
        $numbers[] = $invoice->fresh()->number;
    }

    expect($numbers)->toBe(['TDR-2026-0001', 'TDR-2026-0002']);

    Carbon::setTestNow(Carbon::parse('2027-01-02 15:00:00', 'UTC'));
    $version = platformInvoiceVersion();
    platformInvoiceRegistrants($version, 1);
    platformInvoiceManager($version, 'em3@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);
    platformInvoiceService()->send($invoice);

    expect($invoice->fresh()->number)->toBe('TDR-2027-0001');
});

test('issued and due dates follow Net 30', function () {
    Mail::fake();
    $version = platformInvoiceVersion();
    platformInvoiceRegistrants($version, 1);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    platformInvoiceService()->send($invoice);
    $invoice->refresh();

    expect($invoice->issued_at->equalTo(Carbon::now()))->toBeTrue();
    expect($invoice->due_at->equalTo(Carbon::now()->addDays(30)))->toBeTrue();
});

test('zero registrants voids the invoice without sending', function () {
    Mail::fake();
    $version = platformInvoiceVersion();
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    platformInvoiceService()->send($invoice);
    $invoice->refresh();

    expect($invoice->statusEnum())->toBe(PlatformInvoiceStatus::Void);
    expect($invoice->voided_reason)->toBe('Zero registrants');
    expect($invoice->number)->toBeNull();
    Mail::assertNothingSent();
});

test('no Event Managers alerts the founder only, flags the invoice and leaves it pending', function () {
    Mail::fake();
    $version = platformInvoiceVersion();
    platformInvoiceRegistrants($version, 3);
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    platformInvoiceService()->send($invoice);
    $invoice->refresh();

    expect($invoice->statusEnum())->toBe(PlatformInvoiceStatus::Pending);
    expect($invoice->needs_attention)->toBeTrue();
    expect($invoice->send_at)->toBeNull();
    expect($invoice->number)->toBeNull();
    Mail::assertSent(PlatformInvoiceMail::class, 1);
    Mail::assertSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->hasTo('rick@mfrholdings.com')
        && $mail->variant === PlatformInvoiceMail::VARIANT_NO_RECIPIENTS);
});

test('reopen holds a pending invoice and re-close restarts the 24 hour clock', function () {
    $version = platformInvoiceVersion();
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    Carbon::setTestNow(Carbon::now()->addHours(10));
    platformInvoiceService()->holdOnReopen($version);
    expect($invoice->fresh()->statusEnum())->toBe(PlatformInvoiceStatus::Held);
    expect($invoice->fresh()->send_at)->toBeNull();

    Carbon::setTestNow(Carbon::now()->addHours(5));
    platformInvoiceService()->scheduleOnClose($version, null);

    $invoice->refresh();
    expect($invoice->statusEnum())->toBe(PlatformInvoiceStatus::Pending);
    expect($invoice->send_at->equalTo(Carbon::now()->addHours(24)))->toBeTrue();
});

test('a due invoice whose Version was reopened is held, not sent', function () {
    Mail::fake();
    $version = platformInvoiceVersion();
    platformInvoiceRegistrants($version, 2);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);
    $version->update(['status' => 'active']);

    Carbon::setTestNow(Carbon::now()->addDay()->addMinute());
    artisan('invoices:send-due')->assertSuccessful();

    expect($invoice->fresh()->statusEnum())->toBe(PlatformInvoiceStatus::Held);
    Mail::assertNothingSent();
});

test('re-closing after the invoice was sent creates no second invoice and sends no second email', function () {
    Mail::fake();
    $version = platformInvoiceVersion();
    platformInvoiceRegistrants($version, 2);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);
    platformInvoiceService()->send($invoice);

    platformInvoiceService()->holdOnReopen($version);
    platformInvoiceService()->scheduleOnClose($version, null);
    Carbon::setTestNow(Carbon::now()->addDays(2));
    artisan('invoices:send-due')->assertSuccessful();

    expect(VersionPlatformInvoice::where('version_id', $version->id)->count())->toBe(1);
    expect($invoice->fresh()->statusEnum())->toBe(PlatformInvoiceStatus::Sent);
    Mail::assertSent(PlatformInvoiceMail::class, 1);
});

test('the invoice PDF attachment renders with the company and the invoice number', function () {
    Mail::fake();
    $version = platformInvoiceVersion();
    platformInvoiceRegistrants($version, 2);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);
    platformInvoiceService()->send($invoice);

    $sent = Mail::sent(PlatformInvoiceMail::class)->first();
    $attachments = $sent->attachments();
    expect($attachments)->toHaveCount(1);

    $html = view('pdf.platform-invoice', ['data' => $sent->data])->render();
    expect($html)->toContain('MFR Holdings, LLC')
        ->toContain('TDR-2026-0001')
        ->toContain('45 Dayton Crescent')
        ->toContain('$5.50')
        ->toContain('<p class="tagline">It has been a pleasure working with you!</p>');

    // Mail CSS inlines styles onto <strong>, so match the bold tag loosely.
    expect($sent->render())->toMatch('#<strong[^>]*>It has been a pleasure working with you!</strong>#');

    $pdf = PlatformInvoicePdf::render($sent->data);
    expect(str_starts_with($pdf, '%PDF'))->toBeTrue();
});

function platformInvoiceVirtualVersion(?string $candidateStart = '2026-09-15 12:00:00'): Version
{
    $version = platformInvoiceVersion([
        'audition_type' => 'remote',
        'upload_type' => 'audio',
        'results_released_at' => Carbon::parse('2027-02-20 12:00:00', 'UTC'),
    ]);

    if ($candidateStart !== null) {
        VersionDate::create([
            'version_id' => $version->id,
            'date_type' => 'candidate',
            'start_at' => Carbon::parse($candidateStart, 'UTC'),
        ]);
    }

    return $version;
}

test('storage months round any partial month up', function (string $start, string $end, int $months) {
    expect(StorageCharge::monthsRoundedUp(Carbon::parse($start), Carbon::parse($end)))->toBe($months);
})->with([
    'Aug 15 to Mar 20 (7 mo 5 days)' => ['2026-08-15', '2027-03-20', 8],
    'Aug 15 to Mar 15 (exactly 7)' => ['2026-08-15', '2027-03-15', 7],
    'Jan 31 to Feb 28 (no overflow)' => ['2027-01-31', '2027-02-28', 1],
    'same day' => ['2026-08-15', '2026-08-15', 1],
]);

test('a virtual Version adds a storage line from one month before the Candidate date to one month after release', function () {
    Mail::fake();
    $version = platformInvoiceVirtualVersion();
    platformInvoiceRegistrants($version, 10);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    expect(platformInvoiceService()->send($invoice))->toBe(PlatformInvoiceSendOutcome::Sent);
    $invoice->refresh();

    // Source dates are stored; the padded window is derived.
    expect($invoice->storage_access_at->toDateString())->toBe('2026-09-15');
    expect($invoice->storage_close_at->toDateString())->toBe('2027-02-20');

    // Aug 15 2026 to Mar 20 2027 = 7 months 5 days, rounded up to 8 months.
    $storage = PlatformInvoiceData::fromInvoice($invoice)->storage;
    expect($storage->startsAt()->toDateString())->toBe('2026-08-15');
    expect($storage->endsAt()->toDateString())->toBe('2027-03-20');
    expect($invoice->storage_months)->toBe(8);
    expect($invoice->storage_rate_cents)->toBe(2495);
    expect($invoice->amount_cents)->toBe(10 * 275 + 8 * 2495);

    Mail::assertSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->data->storage?->months === 8
        && $mail->data->amountCents === 22710);

    $html = view('pdf.platform-invoice', ['data' => PlatformInvoiceData::fromInvoice($invoice)])->render();
    expect($html)->toContain('Virtual audition file storage')
        ->toContain('Candidate Access Date (09/15/2026) - Event Close Date (02/20/2027) + 1 month pre and post for testing and post-event review')
        ->toContain('$199.60')
        ->toContain('$227.10');
});

test('only Remote auditions with Audio or Video uploads count as virtual', function (string $auditionType, string $uploadType, bool $virtual) {
    $version = platformInvoiceVersion(['audition_type' => $auditionType, 'upload_type' => $uploadType]);

    expect(platformInvoiceService()->isVirtual($version))->toBe($virtual);
})->with([
    'remote + audio' => ['remote', 'audio', true],
    'remote + video' => ['remote', 'video', true],
    'remote + none' => ['remote', 'none', false],
    'in person + audio' => ['in_person', 'audio', false],
    'in person + none' => ['in_person', 'none', false],
]);

test('a non-virtual Version has no storage line', function () {
    Mail::fake();
    $version = platformInvoiceVersion(['audition_type' => 'in_person', 'upload_type' => 'video']);
    VersionDate::create(['version_id' => $version->id, 'date_type' => 'candidate', 'start_at' => Carbon::parse('2026-09-15', 'UTC')]);
    platformInvoiceRegistrants($version, 2);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    platformInvoiceService()->send($invoice);
    $invoice->refresh();

    expect($invoice->storage_months)->toBeNull();
    expect($invoice->amount_cents)->toBe(550);
});

test('a virtual Version with no Candidate date is held for attention and alerts the founder', function () {
    Mail::fake();
    $version = platformInvoiceVirtualVersion(null);
    platformInvoiceRegistrants($version, 3);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    expect(platformInvoiceService()->send($invoice))->toBe(PlatformInvoiceSendOutcome::NoCandidateDate);
    $invoice->refresh();

    expect($invoice->statusEnum())->toBe(PlatformInvoiceStatus::Pending);
    expect($invoice->needs_attention)->toBeTrue();
    expect($invoice->attention_reason)->toBe(PlatformInvoiceService::ATTENTION_NO_CANDIDATE_DATE);
    expect($invoice->send_at)->toBeNull();
    expect($invoice->number)->toBeNull();
    Mail::assertSent(PlatformInvoiceMail::class, 1);
    Mail::assertSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->hasTo('rick@mfrholdings.com')
        && ! $mail->hasTo('em@example.com')
        && $mail->variant === PlatformInvoiceMail::VARIANT_NO_CANDIDATE_DATE);
});

test('a virtual Version with zero registrants is still voided', function () {
    Mail::fake();
    $version = platformInvoiceVirtualVersion();
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    expect(platformInvoiceService()->send($invoice))->toBe(PlatformInvoiceSendOutcome::Voided);
    Mail::assertNothingSent();
});

// Storage-included (all-in) billing: docs/plans/storage-included-billing.md

function platformInvoiceAllInVersion(?string $candidateStart = '2026-09-15 12:00:00', int $rateCents = 400): Version
{
    $version = platformInvoiceVirtualVersion($candidateStart);
    Event::whereKey($version->event_id)->update(['platform_fee_per_registrant_cents' => $rateCents, 'platform_fee_includes_storage' => true]);

    return $version->fresh();
}

test('an all-in virtual Version bills registrants only and snapshots storage included', function () {
    Mail::fake();
    $version = platformInvoiceAllInVersion();
    platformInvoiceRegistrants($version, 35);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    expect(platformInvoiceService()->send($invoice))->toBe(PlatformInvoiceSendOutcome::Sent);
    $invoice->refresh();

    expect($invoice->rate_cents)->toBe(400);
    expect($invoice->amount_cents)->toBe(35 * 400);
    expect($invoice->storage_included)->toBeTrue();
    expect($invoice->storage_access_at)->toBeNull();
    expect($invoice->storage_close_at)->toBeNull();
    expect($invoice->storage_months)->toBeNull();
    expect($invoice->storage_rate_cents)->toBeNull();
});

test('the all-in PDF and email show the included note and no storage charge', function () {
    Mail::fake();
    $version = platformInvoiceAllInVersion();
    platformInvoiceRegistrants($version, 3);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);
    platformInvoiceService()->send($invoice);

    $sent = Mail::sent(PlatformInvoiceMail::class)->first();
    expect($sent->data->storageIncluded)->toBeTrue();
    expect($sent->data->storage)->toBeNull();

    $html = view('pdf.platform-invoice', ['data' => PlatformInvoiceData::fromInvoice($invoice->fresh())])->render();
    expect($html)->toContain('Virtual audition file storage included')->toContain('$12.00');
    expect(str_contains($html, 'Candidate Access Date'))->toBeFalse();
    expect(str_contains($html, '/mo'))->toBeFalse();

    $mail = $sent->render();
    expect($mail)->toContain('File storage: included');
    expect(str_contains($mail, '$24.95'))->toBeFalse();
});

test('an all-in virtual Version with no Candidate date is sent without a founder alert', function () {
    Mail::fake();
    $version = platformInvoiceAllInVersion(null);
    platformInvoiceRegistrants($version, 2);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    expect(platformInvoiceService()->send($invoice))->toBe(PlatformInvoiceSendOutcome::Sent);

    Mail::assertSent(PlatformInvoiceMail::class, 1);
    Mail::assertSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->hasTo('em@example.com')
        && $mail->variant === PlatformInvoiceMail::VARIANT_INVOICE);
    expect($invoice->fresh()->amount_cents)->toBe(800);
});

test('an invoice held for a missing Candidate date sends once its Event includes storage', function () {
    Mail::fake();
    $version = platformInvoiceVirtualVersion(null);
    platformInvoiceRegistrants($version, 2);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    expect(platformInvoiceService()->send($invoice))->toBe(PlatformInvoiceSendOutcome::NoCandidateDate);

    Event::whereKey($version->event_id)->update(['platform_fee_per_registrant_cents' => 400, 'platform_fee_includes_storage' => true]);

    expect(platformInvoiceService()->send($invoice->fresh()))->toBe(PlatformInvoiceSendOutcome::Sent);
    $invoice->refresh();
    expect($invoice->storage_included)->toBeTrue();
    expect($invoice->needs_attention)->toBeFalse();
    expect($invoice->attention_reason)->toBeNull();
});

test('an all-in Event on a non-virtual Version snapshots storage included false and shows no note', function () {
    Mail::fake();
    $version = platformInvoiceVersion(['audition_type' => 'in_person', 'upload_type' => 'none'], ['platform_fee_per_registrant_cents' => 400, 'platform_fee_includes_storage' => true]);
    platformInvoiceRegistrants($version, 2);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    platformInvoiceService()->send($invoice);
    $invoice->refresh();

    expect($invoice->storage_included)->toBeFalse();
    expect($invoice->amount_cents)->toBe(800);
    $html = view('pdf.platform-invoice', ['data' => PlatformInvoiceData::fromInvoice($invoice)])->render();
    expect(str_contains($html, 'file storage included'))->toBeFalse();
});

test('changing the Event flag after sending does not change a resent invoice', function (bool $startIncluded) {
    Mail::fake();
    $version = $startIncluded ? platformInvoiceAllInVersion() : platformInvoiceVirtualVersion();
    platformInvoiceRegistrants($version, 2);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);
    platformInvoiceService()->send($invoice);
    $before = $invoice->fresh();

    Event::whereKey($version->event_id)->update(['platform_fee_per_registrant_cents' => 400, 'platform_fee_includes_storage' => ! $startIncluded]);
    platformInvoiceService()->resend($before);

    $resent = Mail::sent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->variant === PlatformInvoiceMail::VARIANT_RESEND)->first();
    expect($resent->data->storageIncluded)->toBe($startIncluded);
    expect($resent->data->amountCents)->toBe($before->amount_cents);
    expect($resent->data->storage === null)->toBe($startIncluded);
})->with(['turned off after an all-in send' => [true], 'turned on after a storage-billed send' => [false]]);

test('turning the flag on while pending applies at send time', function () {
    Mail::fake();
    $version = platformInvoiceVirtualVersion();
    platformInvoiceRegistrants($version, 2);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    Event::whereKey($version->event_id)->update(['platform_fee_per_registrant_cents' => 400, 'platform_fee_includes_storage' => true]);
    Carbon::setTestNow(Carbon::now()->addDay()->addMinute());
    artisan('invoices:send-due')->assertSuccessful();

    $invoice->refresh();
    expect($invoice->statusEnum())->toBe(PlatformInvoiceStatus::Sent);
    expect($invoice->storage_included)->toBeTrue();
    expect($invoice->storage_months)->toBeNull();
    expect($invoice->amount_cents)->toBe(800);
});

test('sendTest on an all-in Version with no Candidate date reports no missing storage line', function () {
    Mail::fake();
    $version = platformInvoiceAllInVersion(null);
    platformInvoiceRegistrants($version, 2);

    expect(platformInvoiceService()->sendTest($version))->toBeFalse();
    Mail::assertSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->variant === PlatformInvoiceMail::VARIANT_TEST
        && $mail->data->storageIncluded
        && $mail->data->storage === null
        && $mail->data->amountCents === 800);

    // The storage-billed path still warns.
    expect(platformInvoiceService()->sendTest(platformInvoiceVirtualVersion(null)))->toBeTrue();
});

test('a 3.00 override without the flag still bills storage (Missouri terms)', function () {
    Mail::fake();
    $version = platformInvoiceVirtualVersion();
    Event::whereKey($version->event_id)->update(['platform_fee_per_registrant_cents' => 300]);
    platformInvoiceRegistrants($version, 10);
    platformInvoiceManager($version, 'em@example.com');
    $invoice = platformInvoiceService()->scheduleOnClose($version, null);

    platformInvoiceService()->send($invoice);
    $invoice->refresh();

    expect($invoice->storage_included)->toBeFalse();
    expect($invoice->storage_months)->toBe(8);
    expect($invoice->amount_cents)->toBe(10 * 300 + 8 * 2495);
});
