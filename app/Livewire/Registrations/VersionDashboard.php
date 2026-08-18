<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\GuardsAcceptedObligations;
use App\Concerns\HasCandidateChecklist;
use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\Vendor;
use App\Enums\VersionDateType;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionTeacherEpaymentOptIn;
use App\Models\VoicePart;
use App\Services\CandidateService;
use App\Services\PaymentAllocationService;
use App\Services\Payments\PaymentGatewayFactory;
use App\Services\VersionInvitationEligibilityService;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

#[Layout('components.layouts.app')]
class VersionDashboard extends Component
{
    use GuardsAcceptedObligations;
    use HasCandidateChecklist;

    public Version $version;

    #[Url]
    public string $search = '';

    #[Url]
    public string $voicePartFilter = '';

    #[Url]
    public string $statusFilter = '';

    /**
     * @var array<int, int>
     */
    public array $selectedCandidateIds = [];

    public ?int $allocatingTransactionId = null;

    /**
     * candidate_id => dollar-string amount, keyed as strings because
     * Livewire's wire:model binds array keys as strings regardless.
     *
     * @var array<string, string>
     */
    public array $allocationAmounts = [];

    /**
     * Page-level counterpart to EligibilityService::isNotInvited() — without
     * this, any teacher with an active school could open this page directly
     * (nav visibility alone is not an authorization boundary) and enroll
     * candidates into a Version they were never invited to.
     */
    public function mount(Version $version, VersionInvitationEligibilityService $eligibility): void
    {
        $teacher = $this->teacher();

        $invitation = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->first();

        if ($invitation === null) {
            if ($eligibility->isEligible($version, $teacher)) {
                $this->redirect(route('registrations.request-invitation', $version), navigate: true);

                return;
            }

            abort(403);
        }

        // A teacher who has never responded to a published obligation, or who
        // rejected it, is sent there first — a rejection is not a terminal
        // "you're done here" state (it flips back the moment they accept
        // again), but until then this Version's other pages are as
        // inaccessible as if no Obligation record existed at all.
        if ($this->redirectUnlessObligationsAccepted($version, $invitation)) {
            return;
        }

        $this->version = $version;
    }

    public function withdraw(CandidateService $candidates, int $candidateId): void
    {
        $candidate = Candidate::where('id', $candidateId)
            ->where('teacher_id', $this->teacher()->id)
            ->firstOrFail();

        $name = $candidate->program_name;
        $candidates->withdraw($candidate);

        Flux::toast("{$name} has been withdrawn.");
    }

    public function refreshStatus(CandidateService $candidates, int $candidateId): void
    {
        $candidate = Candidate::where('id', $candidateId)
            ->where('teacher_id', $this->teacher()->id)
            ->with(['student.user', 'student.homeAddress', 'student.emergencyContacts'])
            ->firstOrFail();

        $checklistDefs = $this->checklistDefs($this->version);
        $candidates->recalculateStatus($candidate, $checklistDefs);

        Flux::toast("{$candidate->program_name} status updated.");
    }

    /**
     * Group payment — one payment_transactions row (source=teacher_epayment)
     * covering every selected Candidate's total, unallocated until
     * reconciled. See epayment-integration.md §1.1/§3.
     */
    public function payForSelected(PaymentGatewayFactory $factory, string $feeType): void
    {
        $feeType = FeeType::from($feeType);

        abort_unless($this->version->epaymentTeacherReady(), 403);
        abort_unless(match ($feeType) {
            FeeType::Registration => $this->version->registrationFeePayable(),
            FeeType::Participation => $this->version->participationFeePayable(),
            FeeType::Housing => $this->version->housingFeePayable(),
        }, 403);
        abort_if($this->selectedCandidateIds === [], 422, 'Select at least one candidate to pay for.');

        $eligibleStates = match ($feeType) {
            FeeType::Registration => CandidateStatus::registrationFeeEligibleStates(),
            FeeType::Participation, FeeType::Housing => [CandidateStatus::Accepted],
        };

        // Defensive filter — the roster UI already only offers checkboxes for
        // candidates eligible for the currently-active FeeType.
        $candidates = Candidate::whereIn('id', $this->selectedCandidateIds)
            ->where('version_id', $this->version->id)
            ->where('teacher_id', $this->teacher()->id)
            ->get()
            // getRawOriginal(), not the magic-cast property — Larastan can't
            // infer the enum cast through this method-based casts() return
            // here (cataloged Larastan quirk; see PHPStan-quirks memory).
            ->filter(fn (Candidate $candidate): bool => in_array(
                CandidateStatus::from((string) $candidate->getRawOriginal('status')),
                $eligibleStates,
                true,
            ))
            ->values();

        abort_if($candidates->isEmpty(), 422);

        $gateway = $factory->make($this->version);
        $session = $gateway->createCheckoutSession($this->version, $candidates, $this->teacher(), $feeType);

        $this->redirect($session->redirectUrl, navigate: false);
    }

    /**
     * Roster-wide, not per-Candidate — toggles whether every Candidate this
     * teacher manages in this Version may use e-payment, once a real gateway
     * exists (see VersionTeacherEpaymentOptIn's own docblock). Gated on
     * epayment_student, not epaymentCredential's old presence-as-gate — see
     * epayment-integration.md §1.3.
     */
    public function toggleEpaymentOptIn(): void
    {
        if (! $this->version->epaymentStudentEnabled()) {
            return;
        }

        $optIn = VersionTeacherEpaymentOptIn::firstOrNew([
            'version_id' => $this->version->id,
            'teacher_id' => $this->teacher()->id,
        ]);
        $optIn->opted_in = ! $optIn->opted_in;
        $optIn->save();

        Flux::toast($optIn->opted_in
            ? 'E-payment enabled for all of your candidates in this Version.'
            : 'E-payment disabled for all of your candidates in this Version.');
    }

    /**
     * Opens the allocate-to-candidates modal for one of this teacher's own
     * unreconciled transactions — the teacher-scoped half of the shared
     * action described in epayment-integration.md §3 (the Registration
     * Manager's own, unscoped, full-Version view is §4 step 8).
     */
    public function openAllocate(int $transactionId): void
    {
        PaymentTransaction::where('id', $transactionId)
            ->where('version_id', $this->version->id)
            ->where('payer_teacher_id', $this->teacher()->id)
            ->firstOrFail();

        $this->allocatingTransactionId = $transactionId;
        $this->allocationAmounts = [];
        $this->resetErrorBag();
        $this->modal('allocate-payment')->show();
    }

    public function openPaymentRegister(): void
    {
        $this->modal('payment-register')->show();
    }

    /**
     * Marks the first-visit spotlight tour as seen for this user — a single
     * flag covers every Version's dashboard (not scoped per Version), since
     * the page layout is the same everywhere a teacher sees it and a
     * returning teacher who's already taken the tour once shouldn't have it
     * auto-launch again on a different Version. Called from the client-side
     * tour engine (resources/views/livewire/registrations/version-dashboard.blade.php)
     * via a hidden wire:click trigger when the tour finishes or is skipped —
     * it does not stop the tour from being relaunched manually at any time
     * via the always-visible "Take a tour" button, which ignores this flag.
     */
    public function dismissOrientation(): void
    {
        Auth::user()->update(['dismissed_registration_orientation_at' => now()]);
    }

    /**
     * Every payment_allocations row across this teacher's own roster in this
     * Version — manual entries, refunds, and e-payments alike — ordered by
     * the candidate's sort_name, then payment chronology within that
     * candidate. paid_at falls back to created_at for the rare row that
     * hasn't actually been paid yet (a pending checkout session). Reused by
     * the CSV/PDF export controllers, the same pattern as
     * PaymentReconciliation's own baseRows() (see that class's docblock).
     *
     * @return Collection<int, covariant array{candidate: Candidate, paidAt: \Carbon\Carbon, type: string, amountCents: int, referenceNumber: ?string, status: PaymentTransactionStatus}>
     */
    public static function paymentRegisterRows(Version $version, Teacher $teacher): Collection
    {
        $candidateIds = Candidate::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->pluck('id');

        $allocations = PaymentAllocation::whereIn('candidate_id', $candidateIds)
            ->with(['paymentTransaction', 'candidate.student.user'])
            ->get();

        $rows = [];

        foreach ($allocations as $allocation) {
            // Both relations are FK-guaranteed non-null (cascadeOnDelete,
            // never orphaned) — the null-coalescing throw exists only to
            // narrow the type for PHPStan/Larastan, which types belongsTo
            // relation properties as nullable regardless.
            $candidate = $allocation->candidate ?? throw new RuntimeException('Payment allocation missing its candidate.');
            $transaction = $allocation->paymentTransaction ?? throw new RuntimeException('Payment allocation missing its transaction.');

            // getRawOriginal(), not the magic-cast property — Larastan can't
            // reliably infer casts through this model's method-based
            // casts() return (cataloged PHPStan-quirks memory; applies
            // beyond just the enum casts it names).
            $paymentType = $transaction->getRawOriginal('payment_type');
            $vendor = $transaction->getRawOriginal('vendor');
            $source = PaymentSource::from((string) $transaction->getRawOriginal('source'));
            $paidAtRaw = $transaction->getRawOriginal('paid_at');

            $type = match (true) {
                $paymentType !== null => PaymentType::from($paymentType)->label(),
                $vendor !== null => Vendor::from($vendor)->label(),
                default => $source->label(),
            };

            $rows[] = [
                'candidate' => $candidate,
                'paidAt' => Carbon::parse($paidAtRaw ?? (string) $transaction->getRawOriginal('created_at')),
                'type' => $type,
                'amountCents' => (int) $allocation->amount,
                'referenceNumber' => $transaction->reference_number,
                'status' => PaymentTransactionStatus::from((string) $transaction->getRawOriginal('status')),
            ];
        }

        // usort, not Collection::sortBy() with a multi-criteria array — a
        // 1-arg callback there silently misorders (cataloged PHPStan-quirks
        // memory); a plain array-tuple <=> comparison sorts by sort_name
        // then payment chronology correctly.
        usort(
            $rows,
            fn (array $a, array $b): int => [mb_strtolower($a['candidate']->student->user->sort_name), $a['paidAt']->format('Y-m-d H:i:s')]
                <=> [mb_strtolower($b['candidate']->student->user->sort_name), $b['paidAt']->format('Y-m-d H:i:s')],
        );

        return new Collection($rows);
    }

    public function saveAllocations(PaymentAllocationService $allocations): void
    {
        abort_if($this->allocatingTransactionId === null, 400);

        $transaction = PaymentTransaction::where('id', $this->allocatingTransactionId)
            ->where('version_id', $this->version->id)
            ->where('payer_teacher_id', $this->teacher()->id)
            ->firstOrFail();

        $rosterCandidateIds = Candidate::where('version_id', $this->version->id)
            ->where('teacher_id', $this->teacher()->id)
            ->pluck('id')
            ->all();

        $amountsByCandidateId = [];

        foreach ($this->allocationAmounts as $candidateId => $rawAmount) {
            if ($rawAmount === '') {
                continue;
            }

            $candidateId = (int) $candidateId;
            abort_unless(in_array($candidateId, $rosterCandidateIds, true), 403);

            $amountsByCandidateId[$candidateId] = (int) round(((float) $rawAmount) * 100);
        }

        if ($amountsByCandidateId === []) {
            $this->addError('allocationAmounts', 'Enter at least one amount.');

            return;
        }

        $allocations->allocateMany($transaction, $amountsByCandidateId, Auth::user());

        $this->allocatingTransactionId = null;
        $this->modal('allocate-payment')->close();

        Flux::toast('Payment allocated.', variant: 'success');
    }

    public function render(): View
    {
        $teacher = $this->teacher();

        // Sorted by the student's sort_name (Last, First — the same "alpha
        // order" convention used elsewhere, e.g. VersionInvitationEligibilityService::roster()),
        // not program_name — a teacher can freely edit program_name to
        // anything for the concert program, so it isn't a reliable
        // alphabetical key.
        $myCandidates = Candidate::where('version_id', $this->version->id)
            ->where('teacher_id', $teacher->id)
            ->with(['student.user', 'student.homeAddress', 'student.emergencyContacts', 'voicePart'])
            ->get()
            ->sortBy(fn (Candidate $candidate): string => mb_strtolower($candidate->student->user->sort_name));

        $filteredCandidates = $this->filterCandidates($myCandidates);

        // "Paid" column — sum of Completed payment_allocations per
        // candidate, refunds included (their negative amount nets out of
        // the total), same computation as PaymentReconciliation::baseRows()'
        // own $paidByCandidateId.
        $paidByCandidateId = PaymentAllocation::whereIn('candidate_id', $myCandidates->pluck('id'))
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', PaymentTransactionStatus::Completed->value))
            ->get()
            ->groupBy('candidate_id')
            ->map(fn (Collection $allocations): int => (int) $allocations->sum('amount'));

        // Summary tables reflect the full roster (myCandidates), not the
        // filtered view — a stable overview regardless of the search/filter
        // row below it.
        // Registered-only: this table answers "how many per voice part have
        // actually made it to Registered," not a raw headcount across every
        // status — the "Registered" column is the sum of that, not a
        // cross-status grand total.
        $voicePartCounts = $this->version->availableVoiceParts()
            ->map(fn (VoicePart $voicePart): array => [
                'label' => $voicePart->abbr,
                'count' => $myCandidates->where('voice_part_id', $voicePart->id)->where('status', CandidateStatus::Registered)->count(),
            ]);
        $voicePartTotal = $voicePartCounts->sum('count');

        $statusCounts = collect(CandidateStatus::registrationStates())
            ->map(fn (CandidateStatus $status): array => [
                'label' => $status->label(),
                'count' => $myCandidates->where('status', $status)->count(),
            ]);
        $statusTotal = $statusCounts->sum('count');

        $statusOptions = $myCandidates
            ->pluck('status')
            ->unique(fn (CandidateStatus $status): string => $status->value)
            ->sortBy(fn (CandidateStatus $status): string => $status->label())
            ->values();

        $upcomingDates = $this->version->dates()
            ->where('start_at', '>=', now())
            ->whereNotIn('date_type', [VersionDateType::TabRoom->value, VersionDateType::Rehearsal->value])
            ->orderByRaw('COALESCE(end_at, start_at)')
            ->limit(6)
            ->get();

        $voiceParts = VoicePart::ordered()->get();

        $checklistDefs = $this->checklistDefs($this->version);

        $epaymentTeacherReady = $this->version->epaymentTeacherReady();

        // Registration's window is mutually exclusive with the other two
        // (see Version::registrationFeePayable()/participationFeePayable()/
        // housingFeePayable()), but participation and housing share the
        // exact same timing, so both can be simultaneously chargeable once
        // the Version closes — $activeFeeTypes is a list (0-2 entries), not
        // a single value; the roster's checkbox-eligibility set is still
        // singular, since registration-eligible and Accepted-only never
        // apply on the same render (confirmed with the product owner,
        // studentfolder-module.md §0 second pass, 2026-08-18).
        $activeFeeTypes = collect();
        if ($epaymentTeacherReady && $this->version->registrationFeePayable()) {
            $activeFeeTypes->push(FeeType::Registration);
        }
        if ($epaymentTeacherReady && $this->version->participationFeePayable()) {
            $activeFeeTypes->push(FeeType::Participation);
        }
        if ($epaymentTeacherReady && $this->version->housingFeePayable()) {
            $activeFeeTypes->push(FeeType::Housing);
        }

        $feeEligibleStatuses = match (true) {
            $activeFeeTypes->contains(FeeType::Registration) => CandidateStatus::registrationFeeEligibleStates(),
            $activeFeeTypes->isNotEmpty() => [CandidateStatus::Accepted],
            default => [],
        };

        $epaymentStudentEnabled = $this->version->epaymentStudentEnabled();
        $epaymentOptedIn = $epaymentStudentEnabled && (bool) VersionTeacherEpaymentOptIn::where('version_id', $this->version->id)
            ->where('teacher_id', $teacher->id)
            ->value('opted_in');

        // "Your Unreconciled Payments" (§3) — this teacher's own
        // transactions with a remaining unallocated balance. Eager-loading
        // allocations avoids an N+1 in unallocatedAmount() across the list.
        $unreconciledPayments = PaymentTransaction::where('version_id', $this->version->id)
            ->where('payer_teacher_id', $teacher->id)
            ->with('allocations')
            ->get()
            ->filter(fn (PaymentTransaction $transaction): bool => $transaction->needsReconciliation())
            ->values();

        $paymentRegisterRows = self::paymentRegisterRows($this->version, $teacher);

        $showOrientation = Auth::user()->dismissed_registration_orientation_at === null;

        return view('livewire.registrations.version-dashboard', compact(
            'myCandidates', 'filteredCandidates', 'paidByCandidateId', 'voicePartCounts', 'voicePartTotal',
            'statusCounts', 'statusTotal', 'statusOptions',
            'upcomingDates', 'voiceParts', 'checklistDefs',
            'activeFeeTypes', 'feeEligibleStatuses', 'epaymentStudentEnabled', 'epaymentOptedIn', 'unreconciledPayments',
            'paymentRegisterRows', 'showOrientation',
        ));
    }

    /**
     * @param  Collection<int, Candidate>  $candidates
     * @return Collection<int, Candidate>
     */
    private function filterCandidates(Collection $candidates): Collection
    {
        $search = mb_strtolower(trim($this->search));

        if ($search !== '') {
            $candidates = $candidates->filter(fn (Candidate $candidate): bool => str_contains(mb_strtolower((string) $candidate->student->user->name), $search));
        }

        if ($this->voicePartFilter !== '') {
            $candidates = $candidates->filter(fn (Candidate $candidate): bool => (string) $candidate->voice_part_id === $this->voicePartFilter);
        }

        if ($this->statusFilter !== '') {
            $candidates = $candidates->filter(fn (Candidate $candidate): bool => $candidate->getRawOriginal('status') === $this->statusFilter);
        }

        return $candidates->values();
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
