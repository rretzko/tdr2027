<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Enums\CandidateStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\School;
use App\Models\Teacher;
use App\Models\Version;
use App\Services\PaymentAllocationService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Registration-Manager-facing replacement for Payment Roster
 * (epayment-integration.md §3/§4 step 8) — a "Needs Reconciliation" queue
 * (every payment_transactions row still short of fully allocated, Version-
 * wide) plus school- and Version-level balance rollups computed from
 * payment_allocations, not the old candidate_payments/teacher_payments
 * union. The allocate-to-candidates action reuses PaymentAllocationService,
 * the same one VersionDashboard's teacher-scoped "Your Unreconciled
 * Payments" section uses (§4 step 6) — this view is simply unscoped: any
 * candidate in the Version, not just one teacher's own roster.
 */
#[Layout('components.layouts.app')]
class PaymentReconciliation extends Component
{
    use ScopesReports;

    public Version $version;

    #[Url]
    public string $search = '';

    public ?int $allocatingTransactionId = null;

    public string $allocationCandidateSearch = '';

    /**
     * @var array<string, string>
     */
    public array $allocationAmounts = [];

    #[Locked]
    public ?int $paymentDetailsSchoolId = null;

    #[Locked]
    public bool $canViewPaymentDetails = false;

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        $this->authorizeReports($version, $roles);

        $this->version = $version;

        // The per-school "who paid" drill-down is Event Manager (or Founder)
        // only — Registration/Co-Registration Managers see the balances but
        // not the payment-by-payment detail.
        $this->canViewPaymentDetails = $roles->canManageEvent(Auth::user(), $version->event);
    }

    public function showPaymentDetails(int $schoolId, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);
        abort_unless($this->withinReportScope(School::findOrFail($schoolId)->county_id), 403);

        $this->paymentDetailsSchoolId = $schoolId;
        $this->modal('payment-details')->show();
    }

    /**
     * Unscoped by design — a Registration Manager (or Co-Registration
     * Manager, within their county) can allocate any transaction in the
     * Version to any of that Version's candidates, not just their own
     * roster. Compare VersionDashboard::openAllocate(), which is teacher-
     * scoped.
     */
    public function openAllocate(int $transactionId): void
    {
        $transaction = PaymentTransaction::where('id', $transactionId)
            ->where('version_id', $this->version->id)
            ->firstOrFail();

        abort_unless($this->withinReportScope($transaction->school?->county_id), 403);
        abort_unless($transaction->isAllocatable(), 422, 'This payment has not been completed, so it cannot be allocated yet.');

        $this->allocatingTransactionId = $transaction->id;
        $this->allocationAmounts = [];
        $this->allocationCandidateSearch = '';
        $this->resetErrorBag();
        $this->modal('allocate-payment')->show();
    }

    public function saveAllocations(PaymentAllocationService $allocations): void
    {
        abort_if($this->allocatingTransactionId === null, 400);

        $transaction = PaymentTransaction::where('id', $this->allocatingTransactionId)
            ->where('version_id', $this->version->id)
            ->firstOrFail();

        $versionCandidateIds = Candidate::where('version_id', $this->version->id)->pluck('id')->all();

        $amountsByCandidateId = [];

        foreach ($this->allocationAmounts as $candidateId => $rawAmount) {
            if ($rawAmount === '') {
                continue;
            }

            $candidateId = (int) $candidateId;
            abort_unless(in_array($candidateId, $versionCandidateIds, true), 403);

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
        $allSchoolRows = self::baseRows($this->version, $this->reportCountyIds);

        // The Version-level rollup always reflects every school in scope,
        // regardless of the search box below — only the table itself filters.
        $schoolRows = self::filterRows($allSchoolRows, $this->search);
        $versionTotals = self::versionTotals($allSchoolRows);

        $needsReconciliation = self::unreconciledTransactions($this->version, $this->reportCountyIds);

        $allocatingTransaction = $needsReconciliation->firstWhere('id', $this->allocatingTransactionId);
        $allocationCandidates = $this->allocationCandidates($allocatingTransaction);

        $paymentDetailsRow = $this->canViewPaymentDetails && $this->paymentDetailsSchoolId !== null
            ? $allSchoolRows->first(fn (array $row): bool => $row['school']?->id === $this->paymentDetailsSchoolId)
            : null;

        return view('livewire.events.reports.payment-reconciliation', [
            'schoolRows' => $schoolRows,
            'versionTotals' => $versionTotals,
            'needsReconciliation' => $needsReconciliation,
            'allocatingTransaction' => $allocatingTransaction,
            'allocationCandidates' => $allocationCandidates,
            'paymentDetailsRow' => $paymentDetailsRow,
            'paymentDetails' => $paymentDetailsRow !== null
                ? self::paymentDetails($this->version, $this->paymentDetailsSchoolId)
                : collect(),
        ]);
    }

    /**
     * The payments behind one school's "Paid" figure — one entry per
     * Completed transaction, carrying only the portion of it allocated to
     * this school's non-withdrawn candidates (a group payment can span
     * schools), plus the per-candidate breakdown. Uses exactly the same
     * candidate set and Completed-only rule as baseRows(), so the entries
     * always sum to that row's paidCents.
     *
     * @return Collection<int, array{transaction: PaymentTransaction, amountCents: int, allocations: Collection<int, PaymentAllocation>}>
     */
    public static function paymentDetails(Version $version, int $schoolId): Collection
    {
        $feeEligibleStatusValues = array_map(
            fn (CandidateStatus $s): string => $s->value,
            CandidateStatus::registrationFeeEligibleStates(),
        );

        $allocations = PaymentAllocation::query()
            ->whereHas('candidate', fn ($q) => $q->where('version_id', $version->id)
                ->where('school_id', $schoolId)
                ->whereIn('status', $feeEligibleStatusValues))
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', PaymentTransactionStatus::Completed->value))
            ->with(['candidate.student.user', 'paymentTransaction.payerTeacher.user', 'paymentTransaction.payerStudent.user', 'paymentTransaction.recordedBy'])
            ->get()
            ->sortBy(fn (PaymentAllocation $a): string => mb_strtolower($a->candidate->student->user->last_name.' '.$a->candidate->student->user->first_name))
            ->values();

        /** @var array<int, array{transaction: PaymentTransaction, amountCents: int, allocations: list<PaymentAllocation>}> $entries */
        $entries = [];

        foreach ($allocations as $allocation) {
            $transaction = $allocation->paymentTransaction;

            if ($transaction === null) {
                continue;
            }

            $entries[$transaction->id] ??= ['transaction' => $transaction, 'amountCents' => 0, 'allocations' => []];
            $entries[$transaction->id]['amountCents'] += $allocation->amount;
            $entries[$transaction->id]['allocations'][] = $allocation;
        }

        // Oldest payment first — raw 'Y-m-d H:i:s' strings sort correctly as
        // strings; created_at stands in for a payment with no paid_at.
        return collect(array_values($entries))
            ->sortBy(fn (array $entry): string => (string) ($entry['transaction']->getRawOriginal('paid_at') ?? $entry['transaction']->getRawOriginal('created_at')))
            ->values()
            ->map(fn (array $entry): array => [...$entry, 'allocations' => collect($entry['allocations'])]);
    }

    /**
     * One row per school with a fee-eligible candidate — due/paid/balance.
     * "Due" is registration (always) plus participation (only once the
     * Version is closed, and only for a Candidate who was Accepted) — see
     * FeeType; the two are never combined into one flat per-candidate
     * figure. "Paid" is sum(payment_allocations.amount) for Completed
     * transactions only, resolved via each allocation's own candidate —
     * never payment_transactions.school_id, which is a creation-time triage
     * snapshot only (§1.1). Matches ParticipatingSchools' baseRows() exactly.
     *
     * @param  list<int>|null  $countyIds
     * @return Collection<int, mixed>
     */
    public static function baseRows(Version $version, ?array $countyIds): Collection
    {
        // Broader than status=Registered alone — Ensemble Cut-offs can
        // resolve a Candidate to Accepted/NotAccepted/NoShow/Incomplete
        // before the Version is formally closed, and every one of those
        // outcomes still owes at least the registration fee.
        $feeEligibleStatusValues = array_map(
            fn (CandidateStatus $s): string => $s->value,
            CandidateStatus::registrationFeeEligibleStates(),
        );

        $query = Candidate::where('version_id', $version->id)
            ->whereIn('status', $feeEligibleStatusValues)
            ->with(['school', 'teacher.user']);

        if ($countyIds !== null) {
            $query->whereHas('school', fn ($q) => $q->whereIn('county_id', $countyIds));
        }

        $feeEligibleCandidates = $query->get();

        // Registration and participation are never combined into one figure
        // — see FeeType. Participation is only added once the Version is
        // closed, and only for a Candidate who was actually Accepted.
        $fees = optional($version->fees()->first());
        $registrationCents = (int) ($fees->registration ?? 0);
        $participationCents = (int) ($fees->participation ?? 0);
        $participationPayable = $version->participationFeePayable();

        $dueForCandidate = function (Candidate $candidate) use ($registrationCents, $participationCents, $participationPayable): int {
            $due = $registrationCents;

            // getRawOriginal(), not the magic-cast property — Larastan can't
            // infer the enum cast through this method-based casts() return
            // here (cataloged Larastan quirk; see PHPStan-quirks memory).
            if ($participationPayable && $candidate->getRawOriginal('status') === CandidateStatus::Accepted->value) {
                $due += $participationCents;
            }

            return $due;
        };

        $paidByCandidateId = PaymentAllocation::whereIn('candidate_id', $feeEligibleCandidates->pluck('id'))
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', PaymentTransactionStatus::Completed->value))
            ->get()
            ->groupBy('candidate_id')
            ->map(fn (Collection $g): int => (int) $g->sum('amount'));

        // Only registered (or cut-off-resolved) candidates owe anything — an
        // Eligible/Pending one hasn't registered yet. Paid still sums every
        // non-withdrawn candidate, so money paid ahead for one isn't hidden.
        $owedStatusValues = array_map(
            fn (CandidateStatus $s): string => $s->value,
            CandidateStatus::registrationFeeOwedStates(),
        );
        $owes = fn (Candidate $c): bool => in_array($c->getRawOriginal('status'), $owedStatusValues, true);

        return $feeEligibleCandidates
            ->groupBy('school_id')
            ->map(function (Collection $group) use ($dueForCandidate, $paidByCandidateId, $owes): array {
                $first = $group->first();
                $owing = $group->filter($owes);
                $dueCents = $owing->sum($dueForCandidate);
                $paidCents = $group->sum(fn (Candidate $c): int => $paidByCandidateId->get($c->id, 0));

                return [
                    'school' => $first->school,
                    // A school can have more than one registering teacher
                    // in a Version, so every distinct one is listed.
                    'teachers' => $group->pluck('teacher')
                        ->filter()
                        ->unique('id')
                        ->sortBy(fn (Teacher $t): string => mb_strtolower($t->user->name))
                        ->values(),
                    'count' => $owing->count(),
                    'dueCents' => $dueCents,
                    'paidCents' => $paidCents,
                    'balanceCents' => $dueCents - $paidCents,
                ];
            })
            // A school with only not-yet-registered candidates is shown only
            // if it has already paid something for them.
            ->filter(fn (array $row): bool => $row['count'] > 0 || $row['paidCents'] > 0)
            ->sortBy(fn (array $row): string => mb_strtolower($row['school']->name ?? ''))
            ->values();
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    public static function filterRows(Collection $rows, string $search): Collection
    {
        $search = mb_strtolower(trim($search));

        if ($search === '') {
            return $rows;
        }

        return $rows->filter(fn (array $row): bool => str_contains(mb_strtolower((string) ($row['school']->name ?? '')), $search))->values();
    }

    /**
     * @param  Collection<int, mixed>  $schoolRows
     * @return array{dueCents: int, paidCents: int, balanceCents: int}
     */
    public static function versionTotals(Collection $schoolRows): array
    {
        return [
            'dueCents' => (int) $schoolRows->sum('dueCents'),
            'paidCents' => (int) $schoolRows->sum('paidCents'),
            'balanceCents' => (int) $schoolRows->sum('balanceCents'),
        ];
    }

    /**
     * Every completed payment_transactions row still short of fully
     * allocated, Version-wide, county-scoped via the transaction's own
     * denormalized (triage-only) school_id — see the class docblock.
     * Completed only, matching VersionDashboard's teacher-scoped queue:
     * pending/failed/refunded money can't be allocated to a candidate (see
     * PaymentTransaction::isAllocatable()), and the school/Version balance
     * rollups above already count Completed allocations only.
     *
     * @param  list<int>|null  $countyIds
     * @return Collection<int, PaymentTransaction>
     */
    public static function unreconciledTransactions(Version $version, ?array $countyIds): Collection
    {
        return PaymentTransaction::where('version_id', $version->id)
            ->where('status', PaymentTransactionStatus::Completed->value)
            ->with(['allocations', 'school', 'payerTeacher.user'])
            ->get()
            ->filter(fn (PaymentTransaction $t): bool => $t->needsReconciliation())
            ->filter(fn (PaymentTransaction $t): bool => $countyIds === null || in_array($t->school?->county_id, $countyIds, true))
            ->values();
    }

    /**
     * Candidates offered in the allocate modal — defaults to the
     * transaction's own (denormalized, triage-only) school, narrowed or
     * broadened by search, capped rather than dumping the whole Version
     * roster into the page (the same leak class of bug fixed in
     * VersionDashboard's own allocate modal — see epayment-integration.md
     * §4 step 6).
     *
     * @return Collection<int, Candidate>
     */
    private function allocationCandidates(?PaymentTransaction $transaction): Collection
    {
        if ($transaction === null) {
            return collect();
        }

        $query = Candidate::where('version_id', $this->version->id)->with(['student.user', 'school']);

        if ($this->allocationCandidateSearch !== '') {
            $search = mb_strtolower(trim($this->allocationCandidateSearch));

            return $query->get()
                ->filter(fn (Candidate $c): bool => str_contains(mb_strtolower($c->student->user->name), $search))
                ->take(25)
                ->values();
        }

        if ($transaction->school_id !== null) {
            return $query->where('school_id', $transaction->school_id)->limit(25)->get();
        }

        return collect();
    }
}
