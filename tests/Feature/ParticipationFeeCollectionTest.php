<?php

declare(strict_types=1);

use App\Enums\CandidateStatus;
use App\Enums\EventStatus;
use App\Enums\FeeType;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\Vendor;
use App\Enums\VersionDateType;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Livewire\Events\Reports\ParticipatingSchools;
use App\Livewire\Events\Reports\PaymentReconciliation;
use App\Livewire\Registrations\CandidateDetail;
use App\Livewire\Registrations\ParticipationFees;
use App\Livewire\Registrations\ResultsIndex;
use App\Livewire\Sfdi\Events\Index as SfdiIndex;
use App\Livewire\Sfdi\Events\Show as SfdiShow;
use App\Models\Candidate;
use App\Models\CandidateFeeWaiver;
use App\Models\Ensemble;
use App\Models\Event;
use App\Models\EventEpaymentConfig;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionEpaymentConfig;
use App\Models\VersionFee;
use App\Models\VersionTeacherEpaymentOptIn;
use App\Services\CandidateFeeLedger;
use App\Services\FeeReconciliationService;
use App\Services\PaymentAllocationService;
use App\Services\Payments\Dto\CheckoutSession;
use App\Services\Payments\Dto\WebhookEvent;
use App\Services\Payments\PaymentGatewayContract;
use App\Services\Payments\PaypalPaymentGateway;
use App\Services\Payments\SquarePaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * A closed, released Version that charges $20 registration and $30
 * participation, one opted-in teacher, and a stand-in gateway that records
 * what it was asked to charge.
 */
final class ParticipationScenario
{
    public Version $version;

    public Teacher $teacher;

    public School $school;

    /** @var Collection<int, array{ids: array<int, int>, fee: FeeType}> */
    public Collection $checkouts;

    public function __construct()
    {
        $this->version = Version::factory()->create(['status' => EventStatus::Closed, 'results_released_at' => now()]);
        VersionFee::create(['version_id' => $this->version->id, 'registration' => 2000, 'participation' => 3000]);
        VersionEpaymentConfig::create(['version_id' => $this->version->id, 'epayment_student' => true, 'epayment_teacher' => true]);
        EventEpaymentConfig::create(['event_id' => $this->version->event_id, 'vendor' => Vendor::Square, 'vendor_account_id' => 'loc', 'secret' => 'tok']);

        $this->teacher = Teacher::factory()->create(['onboarding_completed_at' => now()]);
        $this->school = School::factory()->create();
        VersionTeacherEpaymentOptIn::create(['version_id' => $this->version->id, 'teacher_id' => $this->teacher->id, 'opted_in' => true]);

        $this->checkouts = new Collection;
        app()->instance(SquarePaymentGateway::class, new class($this) implements PaymentGatewayContract
        {
            public function __construct(private ParticipationScenario $scenario) {}

            /**
             * @param  Collection<int, Candidate>  $candidates
             */
            public function createCheckoutSession(Version $version, Collection $candidates, Teacher|Student $payer, FeeType $feeType): CheckoutSession
            {
                $this->scenario->checkouts->push(['ids' => $candidates->pluck('id')->sort()->values()->all(), 'fee' => $feeType]);

                return new CheckoutSession(redirectUrl: 'https://pay.example', paymentTransactionId: 1);
            }

            public function verifyWebhookSignature(Request $request, Event $event): bool
            {
                return true;
            }

            public function parseWebhookEvent(Request $request): null
            {
                return null;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function accepted(array $attributes = []): Candidate
    {
        return Candidate::factory()->create([
            'version_id' => $this->version->id,
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'status' => CandidateStatus::Accepted,
            ...$attributes,
        ]);
    }

    public function pay(Candidate $candidate, FeeType $fee, int $cents, PaymentTransactionStatus $status = PaymentTransactionStatus::Completed): PaymentTransaction
    {
        $transaction = PaymentTransaction::create([
            'version_id' => $this->version->id,
            'source' => PaymentSource::CandidateEpayment,
            'vendor' => Vendor::Paypal,
            'vendor_transaction_id' => 'order-'.uniqid(),
            'school_id' => $candidate->school_id,
            'amount' => $cents,
            'status' => $status,
            'fee_type' => $fee,
            'paid_at' => now(),
        ]);
        PaymentAllocation::create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $candidate->id, 'amount' => $cents, 'allocated_at' => now()]);

        return $transaction;
    }
}

test('each fee has its own balance, so paying participation does not pay registration', function () {
    $s = new ParticipationScenario;
    $candidate = $s->accepted();
    $s->pay($candidate, FeeType::Participation, 3000);
    $ledger = app(CandidateFeeLedger::class);

    expect($ledger->balanceCents($s->version, $candidate, FeeType::Participation))->toBe(0)
        ->and($ledger->balanceCents($s->version, $candidate, FeeType::Registration))->toBe(2000);

    Livewire::actingAs($candidate->student->user)
        ->test(SfdiShow::class, ['candidate' => $candidate])
        ->assertDontSee('Pay Participation Fee')
        ->call('payNow', 'participation')
        ->assertNoRedirect();

    Livewire::actingAs($s->teacher->user)
        ->test(ParticipationFees::class, ['version' => $s->version])
        ->set('selectedCandidateIds', [$candidate->id])
        ->call('payParticipation')
        ->assertNoRedirect();

    expect($s->checkouts)->toHaveCount(0);
});

test('a double registration payment does not reduce the participation fee', function () {
    $s = new ParticipationScenario;
    $candidate = $s->accepted();
    $s->pay($candidate, FeeType::Registration, 4000);
    $ledger = app(CandidateFeeLedger::class);

    expect($ledger->balanceCents($s->version, $candidate, FeeType::Participation))->toBe(3000)
        ->and($ledger->overpaymentCents($s->version, $candidate))->toBe(2000);
});

test('an accepted student finds the closed Version and can pay', function () {
    $s = new ParticipationScenario;
    $ensemble = Ensemble::factory()->create(['name' => 'Festival Chorus']);
    $candidate = $s->accepted(['accepted_ensemble_id' => $ensemble->id]);

    Livewire::actingAs($candidate->student->user)
        ->test(SfdiIndex::class)
        ->assertSee($s->version->name)
        ->assertSee('Participation fee');

    Livewire::actingAs($candidate->student->user)
        ->test(SfdiShow::class, ['candidate' => $candidate])
        ->assertSee('Festival Chorus')
        ->assertSee('Balance $30.00')
        ->call('payNow', 'participation')
        ->assertRedirect('https://pay.example');
});

test('a closed Version stays hidden from students who were not accepted, and before results are released', function () {
    $s = new ParticipationScenario;
    $rejected = $s->accepted(['status' => CandidateStatus::NotAccepted]);
    $accepted = $s->accepted();

    Livewire::actingAs($rejected->student->user)->test(SfdiIndex::class)->assertDontSee($s->version->name);

    $s->version->update(['results_released_at' => null]);
    Livewire::actingAs($accepted->student->user)->test(SfdiIndex::class)->assertDontSee($s->version->name);
});

test('a student whose teacher has not opted in is told to pay through the teacher', function () {
    $s = new ParticipationScenario;
    VersionTeacherEpaymentOptIn::query()->update(['opted_in' => false]);
    $candidate = $s->accepted();

    Livewire::actingAs($candidate->student->user)
        ->test(SfdiShow::class, ['candidate' => $candidate])
        ->assertSee('Please pay this fee through your teacher')
        ->assertDontSee('Pay Participation Fee');
});

test('online payment follows the Participation Fee dates', function (int $start, int $end, bool $open) {
    $s = new ParticipationScenario;
    VersionDate::create([
        'version_id' => $s->version->id,
        'date_type' => VersionDateType::ParticipationFee,
        'start_at' => now()->addDays($start),
        'end_at' => now()->addDays($end),
    ]);
    $candidate = $s->accepted();

    $student = Livewire::actingAs($candidate->student->user)->test(SfdiShow::class, ['candidate' => $candidate]);
    $open ? $student->assertSee('Pay Participation Fee') : $student->assertDontSee('Pay Participation Fee');

    $teacher = Livewire::actingAs($s->teacher->user)
        ->test(ParticipationFees::class, ['version' => $s->version])
        ->set('selectedCandidateIds', [$candidate->id])
        ->call('payParticipation');
    $open ? $teacher->assertRedirect('https://pay.example') : $teacher->assertForbidden();
})->with([
    'before' => [1, 5, false],
    'during' => [-1, 5, true],
    'after' => [-5, -1, false],
]);

test('a teacher pays for several students at once, leaving out waived and refusing students already paid or in checkout', function () {
    $s = new ParticipationScenario;
    [$a, $b, $waived] = [$s->accepted(), $s->accepted(), $s->accepted()];
    CandidateFeeWaiver::factory()->create(['candidate_id' => $waived->id, 'fee_type' => FeeType::Participation]);

    $page = Livewire::actingAs($s->teacher->user)
        ->test(ParticipationFees::class, ['version' => $s->version])
        ->set('selectedCandidateIds', [$a->id, $b->id, $waived->id])
        ->call('payParticipation')
        ->assertRedirect('https://pay.example');

    expect($s->checkouts->all())->toBe([['ids' => collect([$a->id, $b->id])->sort()->values()->all(), 'fee' => FeeType::Participation]]);

    $s->pay($a, FeeType::Participation, 3000, PaymentTransactionStatus::Pending);
    Livewire::actingAs($s->teacher->user)
        ->test(ParticipationFees::class, ['version' => $s->version])
        ->set('selectedCandidateIds', [$a->id, $b->id])
        ->call('payParticipation')
        ->assertNoRedirect();

    expect($s->checkouts)->toHaveCount(1);
});

test('Select all unpaid picks only students with a participation balance', function () {
    $s = new ParticipationScenario;
    $paid = $s->accepted();
    $unpaid = $s->accepted();
    $s->pay($paid, FeeType::Participation, 3000);

    Livewire::actingAs($s->teacher->user)
        ->test(ParticipationFees::class, ['version' => $s->version])
        ->call('selectAllUnpaid')
        ->assertSet('selectedCandidateIds', [(string) $unpaid->id]);
});

test('teachers can settle overdue registration after close, but not for unregistered candidates', function () {
    $s = new ParticipationScenario;
    $notAccepted = $s->accepted(['status' => CandidateStatus::NotAccepted]);
    $eligible = $s->accepted(['status' => CandidateStatus::Eligible]);

    Livewire::actingAs($s->teacher->user)
        ->test(ParticipationFees::class, ['version' => $s->version])
        ->assertSee('Registration fees still owed')
        ->set('selectedRegistrationIds', [$notAccepted->id, $eligible->id])
        ->call('payRegistration')
        ->assertRedirect('https://pay.example');

    expect($s->checkouts->first())->toBe(['ids' => [$notAccepted->id], 'fee' => FeeType::Registration]);
});

test('the teacher page lists only the teacher\'s own students and refuses others', function () {
    $s = new ParticipationScenario;
    $mine = $s->accepted();
    $other = $s->accepted(['teacher_id' => Teacher::factory()->create()->id]);

    Livewire::actingAs($s->teacher->user)
        ->test(ParticipationFees::class, ['version' => $s->version])
        ->assertSee($mine->student->user->sort_name)
        ->assertDontSee($other->student->user->sort_name);

    Livewire::actingAs(Teacher::factory()->create()->user)
        ->test(ParticipationFees::class, ['version' => $s->version])
        ->assertForbidden();
});

test('Results links to Participation Fees only for a Version that collects them', function () {
    $s = new ParticipationScenario;
    $s->accepted();
    $url = route('registrations.participation-fees', $s->version);

    Livewire::actingAs($s->teacher->user)->test(ResultsIndex::class)->assertSee($url);

    VersionFee::query()->update(['participation' => 0]);
    Livewire::actingAs($s->teacher->user)->test(ResultsIndex::class)->assertDontSee($url);
});

test('the teacher exports carry participation payments', function () {
    $s = new ParticipationScenario;
    $candidate = $s->accepted();
    $s->pay($candidate, FeeType::Participation, 3000);
    $s->teacher->schools()->attach($s->school->id, ['is_active' => true, 'verified_at' => now()]);
    actingAs($s->teacher->user);

    $csv = get(route('registrations.participation-fees-export', ['version' => $s->version, 'format' => 'csv']))->assertOk()->streamedContent();
    expect($csv)->toContain($candidate->student->user->sort_name)->toContain('PayPal 30.00');

    get(route('registrations.participation-fees-export', ['version' => $s->version, 'format' => 'pdf']))->assertOk();
});

test('a manual payment is recorded against the chosen fee, defaulting to participation after close', function () {
    $s = new ParticipationScenario;
    $candidate = $s->accepted();

    Livewire::actingAs($s->teacher->user)
        ->test(CandidateDetail::class, ['version' => $s->version, 'candidate' => $candidate])
        ->call('recordPayment')
        ->assertSet('payment_fee_type', 'participation')
        ->set('payment_type', 'check')
        ->set('payment_amount', '30')
        ->call('savePayment')
        ->assertHasNoErrors();

    expect(app(CandidateFeeLedger::class)->balanceCents($s->version, $candidate, FeeType::Participation))->toBe(0);

    Livewire::actingAs(makeFounder())
        ->test(ParticipatingSchools::class, ['version' => $s->version])
        ->call('openPayment', $s->school->id, $s->teacher->id)
        ->set('paymentType', 'check')
        ->set('amount', '60')
        ->call('savePayment')
        ->assertHasNoErrors();

    expect(PaymentTransaction::where('amount', 6000)->value('fee_type'))->toBe(FeeType::Participation);
});

test('the Event Manager reconciles one fee by school, ensemble and student', function () {
    $s = new ParticipationScenario;
    $ensemble = Ensemble::factory()->create(['name' => 'Festival Chorus']);
    $paid = $s->accepted(['accepted_ensemble_id' => $ensemble->id]);
    $s->accepted(['accepted_ensemble_id' => $ensemble->id]);
    $s->accepted(['status' => CandidateStatus::NotAccepted]);
    $s->pay($paid, FeeType::Participation, 3000);
    $s->pay($paid, FeeType::Registration, 2000);

    $row = PaymentReconciliation::baseRows($s->version, null, FeeType::Participation)->first();
    expect($row)->toMatchArray(['count' => 2, 'dueCents' => 6000, 'paidCents' => 3000, 'balanceCents' => 3000]);

    $rows = app(FeeReconciliationService::class)->rowsForVersion($s->version, null, FeeType::Participation);
    expect(FeeReconciliationService::ensembleTotals($rows)->get('Festival Chorus'))->toMatchArray(['count' => 2, 'balanceCents' => 3000]);

    $founder = makeFounder();
    Livewire::actingAs($founder)
        ->test(PaymentReconciliation::class, ['version' => $s->version])
        ->set('fee', 'participation')
        ->assertSee('Balances by Ensemble')
        ->assertSee('Participation Fee by Student')
        ->assertSee($paid->student->user->sort_name);

    actingAs($founder);
    $export = fn (string $format, string $detail): string => route('events.versions.reports.payment-reconciliation.export', ['version' => $s->version, 'format' => $format, 'fee' => 'participation', 'detail' => $detail]);
    $order = PaymentTransaction::where('fee_type', 'participation')->value('vendor_transaction_id');

    expect(get($export('csv', 'students'))->assertOk()->streamedContent())->toContain($paid->student->user->sort_name);
    expect(get($export('csv', 'payments'))->assertOk()->streamedContent())->toContain($order);
    get($export('pdf', 'students'))->assertOk();
});

test('a candidate who paid participation and is no longer accepted shows as overpaid', function () {
    $s = new ParticipationScenario;
    $candidate = $s->accepted(['status' => CandidateStatus::NotAccepted]);
    $s->pay($candidate, FeeType::Participation, 3000);

    $row = app(FeeReconciliationService::class)->rowsForVersion($s->version, null, FeeType::Participation)->first();
    expect($row['balanceCents'])->toBe(-3000);
});

test('a partial refund leaves a group payment completed and is recorded against one student', function () {
    $s = new ParticipationScenario;
    [$a, $b] = [$s->accepted(), $s->accepted()];
    $transaction = $s->pay($a, FeeType::Participation, 6000);
    $transaction->allocations()->update(['amount' => 3000]);
    PaymentAllocation::create(['payment_transaction_id' => $transaction->id, 'candidate_id' => $b->id, 'amount' => 3000, 'allocated_at' => now()]);
    $transaction->update(['raw_payload' => ['resource' => ['id' => 'CAP1']]]);

    $refund = new WebhookEvent('', PaymentTransactionStatus::Refunded, 3000, ['event_type' => 'PAYMENT.CAPTURE.REFUNDED'], 'REF1', 'CAP1');
    // Delivered twice: vendors retry.
    ProcessPaymentWebhookJob::dispatchSync(Vendor::Paypal, $refund);
    ProcessPaymentWebhookJob::dispatchSync(Vendor::Paypal, $refund);

    expect($transaction->refresh()->status)->toBe(PaymentTransactionStatus::Completed)
        ->and($transaction->reportedPartialRefunds())->toBe(['REF1' => 3000]);

    Livewire::actingAs(makeFounder())
        ->test(PaymentReconciliation::class, ['version' => $s->version])
        ->assertSee('Refunds to record')
        ->call('openRefund', $transaction->id)
        ->set('refundCandidateId', (string) $b->id)
        ->call('saveRefund')
        ->assertHasNoErrors()
        ->assertDontSee('Refunds to record');

    $ledger = app(CandidateFeeLedger::class);
    expect($ledger->paidCents($a, FeeType::Participation))->toBe(3000)
        ->and($ledger->paidCents($b, FeeType::Participation))->toBe(0)
        ->and(PaymentTransaction::where('payment_type', PaymentType::Refund->value)->value('amount'))->toBe(-3000);
});

test('a group checkout is fully allocated to the selected students, surcharge included', function () {
    $s = new ParticipationScenario;
    [$a, $b] = [$s->accepted(), $s->accepted()];
    $fees = $s->version->fees;
    $fees->update(['epayment_surcharge' => 150]);
    $transaction = PaymentTransaction::create([
        'version_id' => $s->version->id,
        'source' => PaymentSource::TeacherEpayment,
        'payer_teacher_id' => $s->teacher->id,
        'amount' => $fees->amountForCheckout(FeeType::Participation, 2),
        'status' => PaymentTransactionStatus::Pending,
        'fee_type' => FeeType::Participation,
    ]);

    app(PaymentAllocationService::class)->allocateCheckout($transaction, collect([$a, $b]), $fees, FeeType::Participation);

    expect($transaction->load('allocations')->unallocatedAmount())->toBe(0)
        ->and($transaction->allocations->pluck('amount', 'candidate_id')->all())->toEqual([$a->id => 3150, $b->id => 3000]);
    // Nothing counts as paid until the payment completes.
    expect(app(CandidateFeeLedger::class)->paidCents($b, FeeType::Participation))->toBe(0);

    $transaction->update(['status' => PaymentTransactionStatus::Completed]);
    expect(app(CandidateFeeLedger::class)->paidCents($b, FeeType::Participation))->toBe(3000);
});

test('a full refund still refunds the whole payment', function () {
    $s = new ParticipationScenario;
    $transaction = $s->pay($s->accepted(), FeeType::Participation, 3000);

    ProcessPaymentWebhookJob::dispatchSync(Vendor::Paypal, new WebhookEvent((string) $transaction->vendor_transaction_id, PaymentTransactionStatus::Refunded, 3000, [], 'REF1'));

    expect($transaction->refresh()->status)->toBe(PaymentTransactionStatus::Refunded);
});

test('a PayPal refund names its capture', function () {
    $s = new ParticipationScenario;
    $event = app(PaypalPaymentGateway::class)->parseWebhookEvent(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
        'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
        'resource' => ['id' => 'REF1', 'amount' => ['value' => '30.00'], 'links' => [['rel' => 'up', 'href' => 'https://api.paypal.com/v2/payments/captures/CAP1']]],
    ])));

    expect([$event?->vendorCaptureId, $event?->refundId, $event?->amountCents])->toBe(['CAP1', 'REF1', 3000]);
});

test('cancelling at PayPal frees the student to try again and captures nothing', function () {
    $s = new ParticipationScenario;
    $candidate = $s->accepted();
    $transaction = $s->pay($candidate, FeeType::Participation, 3000, PaymentTransactionStatus::Pending);
    $transaction->update(['payer_student_id' => $candidate->student_id]);
    $gateway = Mockery::mock(PaypalPaymentGateway::class);
    $gateway->shouldNotReceive('captureOrder');
    app()->instance(PaypalPaymentGateway::class, $gateway);

    actingAs($candidate->student->user);
    get(route('payments.paypal.return', ['version' => $s->version->id, 'candidate' => $candidate->id, 'cancelled' => 1, 'token' => $transaction->vendor_transaction_id]))
        ->assertRedirect(route('sfdi.events.candidate', $candidate));

    expect($transaction->refresh()->status)->toBe(PaymentTransactionStatus::Expired);
});

test('a pending group checkout stops the student paying the same fee', function () {
    $s = new ParticipationScenario;
    $candidate = $s->accepted();
    $transaction = $s->pay($candidate, FeeType::Participation, 3000, PaymentTransactionStatus::Pending);
    $transaction->update(['source' => PaymentSource::TeacherEpayment, 'payer_teacher_id' => $s->teacher->id]);

    expect($transaction->refresh()->load('allocations')->isAwaitingConfirmation())->toBeTrue();

    Livewire::actingAs($candidate->student->user)
        ->test(SfdiShow::class, ['candidate' => $candidate])
        ->assertSee('Participation payment being confirmed')
        ->call('payNow', 'participation')
        ->assertNoRedirect();
});

test('before payment opens, the page says when and shows the controls disabled', function () {
    $s = new ParticipationScenario;
    $s->accepted();
    VersionDate::create(['version_id' => $s->version->id, 'date_type' => VersionDateType::ParticipationFee, 'start_at' => now()->addDay(), 'end_at' => now()->addDays(5)]);

    Livewire::actingAs($s->teacher->user)
        ->test(ParticipationFees::class, ['version' => $s->version])
        ->assertSee('Online payment opens')
        ->assertSee('Select all unpaid')
        ->assertSeeHtml('x-bind:disabled="true || ');
});

test('the pay button shows the vendor and total', function () {
    $s = new ParticipationScenario;
    $s->accepted();

    Livewire::actingAs($s->teacher->user)
        ->test(ParticipationFees::class, ['version' => $s->version])
        ->assertSee('with Square')
        ->assertSeeHtml('* 3000 + 0')
        ->assertSeeHtml('x-bind:disabled="false || ');
});

test('the teacher Dashboard and Registrations show participation fees due until they are paid', function () {
    $s = new ParticipationScenario;
    $candidate = $s->accepted();
    $s->accepted(['status' => CandidateStatus::NotAccepted]);
    $s->teacher->schools()->attach($s->school->id, ['is_active' => true, 'verified_at' => now()]);
    actingAs($s->teacher->user);

    foreach (['dashboard', 'registrations.index'] as $route) {
        get(route($route))->assertOk()
            ->assertSee('Participation fees due')
            ->assertSee('1 student unpaid, $30.00 due')
            ->assertSee(route('registrations.participation-fees', $s->version));
    }

    $s->pay($candidate, FeeType::Participation, 3000);

    foreach (['dashboard', 'registrations.index'] as $route) {
        get(route($route))->assertOk()->assertDontSee('Participation fees due');
    }
});
