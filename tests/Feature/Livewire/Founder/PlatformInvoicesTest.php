<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\PlatformInvoiceStatus;
use App\Livewire\Founder\PlatformInvoices;
use App\Mail\PlatformInvoiceMail;
use App\Models\Candidate;
use App\Models\Event;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionPlatformInvoice;
use App\Services\PlatformInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function founderInvoicesSentInvoice(int $registrants = 4): VersionPlatformInvoice
{
    $version = Version::factory()->create(['status' => 'closed']);
    Candidate::factory()->count($registrants)->create(['version_id' => $version->id, 'status' => CandidateStatus::Accepted]);

    return VersionPlatformInvoice::factory()->sent('TDR-2026-0001', $registrants)->create(['version_id' => $version->id]);
}

test('a non-founder cannot reach the invoices screen', function () {
    actingAs(User::factory()->create());

    get(route('founder.invoices'))->assertNotFound();
});

test('the founder sees the invoice list', function () {
    founderInvoicesSentInvoice();
    actingAs(makeFounder());

    get(route('founder.invoices'))->assertOk()->assertSee('TDR-2026-0001');
});

test('mark paid and mark unpaid set and clear the paid fields', function () {
    $invoice = founderInvoicesSentInvoice();
    $founder = makeFounder();

    Livewire::actingAs($founder)
        ->test(PlatformInvoices::class)
        ->call('openMarkPaid', $invoice->id)
        ->set('paidOn', '2026-11-15')
        ->set('paymentNote', 'Check #1234')
        ->call('markPaid')
        ->assertHasNoErrors();

    $invoice->refresh();
    expect($invoice->statusEnum())->toBe(PlatformInvoiceStatus::Paid);
    expect($invoice->paid_at->forDisplay()->toDateString())->toBe('2026-11-15');
    expect($invoice->payment_note)->toBe('Check #1234');
    expect($invoice->paid_by_user_id)->toBe($founder->id);

    Livewire::actingAs($founder)
        ->test(PlatformInvoices::class)
        ->call('markUnpaid', $invoice->id);

    $invoice->refresh();
    expect($invoice->statusEnum())->toBe(PlatformInvoiceStatus::Sent);
    expect($invoice->paid_at)->toBeNull();
});

test('resend re-mails the stored snapshot even after the registrant count changes', function () {
    Mail::fake();
    $invoice = founderInvoicesSentInvoice(4);
    $em = User::factory()->create(['email' => 'current-em@example.com']);
    grantVersionRole($em, $invoice->version, 'Event Manager');
    Candidate::factory()->count(3)->create(['version_id' => $invoice->version_id, 'status' => CandidateStatus::Accepted]);

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openResend', $invoice->id)
        ->call('resend');

    Mail::assertSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->hasTo('current-em@example.com')
        && $mail->variant === PlatformInvoiceMail::VARIANT_RESEND
        && $mail->data->number === 'TDR-2026-0001'
        && $mail->data->registrantCount === 4
        && $mail->data->amountCents === 1100);

    $invoice->refresh();
    expect($invoice->resend_count)->toBe(1);
    expect($invoice->registrant_count)->toBe(4);
});

test('resend on a pending invoice explains itself and sends nothing', function () {
    Mail::fake();
    $invoice = VersionPlatformInvoice::factory()->create();

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openResend', $invoice->id)
        ->assertSet('resendInvoiceId', null);

    Mail::assertNothingSent();
});

test('the test send mails only the founder and saves nothing', function () {
    Mail::fake();
    $version = Version::factory()->create(['status' => 'closed']);
    Candidate::factory()->count(2)->create(['version_id' => $version->id, 'status' => CandidateStatus::Accepted]);
    $em = User::factory()->create(['email' => 'em@example.com']);
    grantVersionRole($em, $version, 'Event Manager');

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openTest')
        ->set('testVersionId', $version->id)
        ->call('sendTest')
        ->assertHasNoErrors();

    Mail::assertSent(PlatformInvoiceMail::class, 1);
    Mail::assertSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->hasTo('rick@mfrholdings.com')
        && ! $mail->hasTo('em@example.com')
        && $mail->variant === PlatformInvoiceMail::VARIANT_TEST
        && $mail->data->isTest
        && $mail->data->amountCents === 550);
    expect(VersionPlatformInvoice::count())->toBe(0);
});

test('saving rates stores a per-Event override and clears it when blank', function () {
    $event = Event::factory()->create();

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openRates')
        ->set("rates.{$event->id}", '3.25')
        ->call('saveRates')
        ->assertHasNoErrors();

    expect($event->fresh()->platform_fee_per_registrant_cents)->toBe(325);

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openRates')
        ->set("rates.{$event->id}", '')
        ->call('saveRates');

    expect($event->fresh()->platform_fee_per_registrant_cents)->toBeNull();
});

test('the founder can download an issued invoice PDF', function () {
    $invoice = founderInvoicesSentInvoice();
    actingAs(makeFounder());

    get(route('founder.invoices.pdf', $invoice))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('resend on an invoice held for a missing Candidate date sends it once the date exists', function () {
    Mail::fake();
    $version = Version::factory()->create([
        'status' => 'closed',
        'audition_type' => 'remote',
        'upload_type' => 'video',
        'results_released_at' => now(),
    ]);
    Candidate::factory()->count(2)->create(['version_id' => $version->id, 'status' => CandidateStatus::Accepted]);
    $em = User::factory()->create(['email' => 'virtual-em@example.com']);
    grantVersionRole($em, $version, 'Event Manager');
    $invoice = VersionPlatformInvoice::factory()->create([
        'version_id' => $version->id,
        'send_at' => null,
        'needs_attention' => true,
        'attention_reason' => PlatformInvoiceService::ATTENTION_NO_CANDIDATE_DATE,
    ]);

    // Still no Candidate date: stays held, nothing goes to Event Managers.
    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openResend', $invoice->id)
        ->call('resend');
    expect($invoice->fresh()->statusEnum())->toBe(PlatformInvoiceStatus::Pending);
    Mail::assertNotSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->hasTo('virtual-em@example.com'));

    VersionDate::create(['version_id' => $version->id, 'date_type' => 'candidate', 'start_at' => now()->subMonths(3)]);

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openResend', $invoice->id)
        ->call('resend');

    $invoice->refresh();
    expect($invoice->statusEnum())->toBe(PlatformInvoiceStatus::Sent);
    expect($invoice->storage_months)->toBeGreaterThan(0);
    expect($invoice->attention_reason)->toBeNull();
    Mail::assertSent(PlatformInvoiceMail::class, fn (PlatformInvoiceMail $mail): bool => $mail->hasTo('virtual-em@example.com'));
});

test('saving storage included stores and clears the flag, and an unchanged save reports no changes', function () {
    $event = Event::factory()->create(['platform_fee_per_registrant_cents' => 400]);

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openRates')
        ->set("storageIncluded.{$event->id}", true)
        ->call('saveRates')
        ->assertHasNoErrors();

    expect($event->fresh()->platform_fee_includes_storage)->toBeTrue();
    expect($event->fresh()->platform_fee_per_registrant_cents)->toBe(400);

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openRates')
        ->call('saveRates')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? '') === 'No billing changes to save.');

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openRates')
        ->set("storageIncluded.{$event->id}", false)
        ->call('saveRates');

    expect($event->fresh()->platform_fee_includes_storage)->toBeFalse();
});

test('storage included with a blank rate errors and saves nothing; a typed 0 saves', function () {
    $event = Event::factory()->create(['name' => 'All-In Chapter']);
    $other = Event::factory()->create();

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openRates')
        ->set("rates.{$other->id}", '3.50')
        ->set("storageIncluded.{$event->id}", true)
        ->call('saveRates')
        ->assertHasErrors(["rates.{$event->id}"])
        ->assertSee('Enter a rate for All-In Chapter to include storage.');

    expect($event->fresh()->platform_fee_includes_storage)->toBeFalse();
    expect($other->fresh()->platform_fee_per_registrant_cents)->toBeNull();

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openRates')
        ->set("rates.{$event->id}", '0')
        ->set("storageIncluded.{$event->id}", true)
        ->call('saveRates')
        ->assertHasNoErrors();

    expect($event->fresh()->platform_fee_includes_storage)->toBeTrue();
    expect($event->fresh()->platform_fee_per_registrant_cents)->toBe(0);
});

test('the Rates modal loads stored rates and storage flags', function () {
    $allIn = Event::factory()->create(['platform_fee_per_registrant_cents' => 400, 'platform_fee_includes_storage' => true]);
    $plain = Event::factory()->create();

    Livewire::actingAs(makeFounder())
        ->test(PlatformInvoices::class)
        ->call('openRates')
        ->assertSet("rates.{$allIn->id}", '4.00')
        ->assertSet("storageIncluded.{$allIn->id}", true)
        ->assertSet("rates.{$plain->id}", '')
        ->assertSet("storageIncluded.{$plain->id}", false)
        ->assertSee('Rates and storage');
});

test('the invoice list shows Storage included for a snapshotted invoice', function () {
    $version = Version::factory()->create(['status' => 'closed']);
    VersionPlatformInvoice::factory()->sent('TDR-2026-0001', 4)->create(['version_id' => $version->id, 'storage_included' => true]);
    actingAs(makeFounder());

    get(route('founder.invoices'))->assertOk()->assertSee('Storage included');
});
