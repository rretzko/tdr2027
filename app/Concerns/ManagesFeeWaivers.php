<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\FeeType;
use App\Enums\PaymentTransactionStatus;
use App\Models\Candidate;
use App\Models\CandidateFeeWaiver;
use App\Models\PaymentAllocation;
use App\Services\CandidateFeeCalculator;
use App\Services\CandidateFeeWaiverService;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * The Event Manager's per-student "Waive fees" modal
 * (docs/plans/student-fee-waivers.md), shared by every report that offers
 * it. The using component must have a public `Version $version`, render the
 * `livewire.events.reports.partials.fee-waiver-modal` partial (passing
 * waivableFeeTypes()), and resolve candidate ids within its own scope via
 * feeWaiverCandidate(), so each report keeps its own status/county rules.
 */
trait ManagesFeeWaivers
{
    #[Locked]
    public ?int $waivingCandidateId = null;

    #[Locked]
    public string $waivingCandidateName = '';

    /**
     * Completed payments already allocated to the candidate being edited.
     * Only drives the modal's "this will leave a credit" advisory.
     */
    #[Locked]
    public int $waivingPaidCents = 0;

    /** @var list<string> FeeType values checked in the waiver modal */
    public array $waive_fee_types = [];

    public string $waive_reason = '';

    /**
     * Resolves a candidate id within this report's own scope (Version,
     * status, county), failing with 404 outside it.
     */
    abstract protected function feeWaiverCandidate(int $candidateId): Candidate;

    /**
     * Runs after a successful save, once the waiver modal has closed, e.g.
     * to reopen the list the Event Manager came from.
     */
    protected function afterFeeWaiversSaved(): void {}

    public function openWaivers(int $candidateId, VersionRoleAssignmentService $roles): void
    {
        $this->authorizeFeeWaivers($roles);

        $candidate = $this->feeWaiverCandidate($candidateId);
        $waivers = $candidate->feeWaivers()->get();

        $this->waivingCandidateId = $candidate->id;
        $this->waivingCandidateName = $candidate->student->user->name;
        $this->waive_fee_types = $waivers->map(fn (CandidateFeeWaiver $w): string => (string) $w->getRawOriginal('fee_type'))->values()->all();
        $this->waive_reason = (string) ($waivers->first()->reason ?? '');
        $this->waivingPaidCents = (int) PaymentAllocation::where('candidate_id', $candidate->id)
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', PaymentTransactionStatus::Completed->value))
            ->sum('amount');

        $this->resetErrorBag();
        $this->modal('candidate-fee-waivers')->show();
    }

    public function saveWaivers(VersionRoleAssignmentService $roles, CandidateFeeWaiverService $waiverService): void
    {
        $this->authorizeFeeWaivers($roles);
        abort_if($this->waivingCandidateId === null, 400);

        $candidate = $this->feeWaiverCandidate($this->waivingCandidateId);
        $manageable = array_column($this->waivableFeeTypes(), 'type');

        $validated = $this->validate([
            'waive_fee_types' => ['array'],
            'waive_fee_types.*' => ['string', Rule::in(array_map(fn (FeeType $t): string => $t->value, $manageable))],
            'waive_reason' => [Rule::requiredIf($this->waive_fee_types !== []), 'nullable', 'string', 'max:500'],
        ], [
            'waive_reason.required' => 'Please give a reason for waiving these fees.',
        ]);

        $result = $waiverService->sync(
            $candidate,
            array_map(fn (string $value): FeeType => FeeType::from($value), $validated['waive_fee_types']),
            $manageable,
            trim((string) $validated['waive_reason']),
            Auth::user(),
        );

        $name = $this->waivingCandidateName;
        $this->waivingCandidateId = null;
        $this->modal('candidate-fee-waivers')->close();

        $labels = fn (array $types): string => implode(', ', array_map(fn (FeeType $t): string => $t->label(), $types));

        $added = $result['added'];
        $removed = $result['removed'];

        if ($added !== [] && $removed !== []) {
            $message = "Fee waivers updated for {$name}.";
        } elseif ($added !== []) {
            $message = "Waived for {$name}: {$labels($added)}.";
        } elseif ($removed !== []) {
            $message = "Fee waiver removed for {$name}: {$labels($removed)}.";
        } else {
            $message = "Fee waivers saved for {$name}.";
        }

        Flux::toast($message, variant: 'success');

        $this->afterFeeWaiversSaved();
    }

    /**
     * Fee types the waiver modal offers: only those this Version actually
     * charges (a $0 fee has nothing to waive).
     *
     * @return list<array{type: FeeType, cents: int}>
     */
    protected function waivableFeeTypes(): array
    {
        $calculator = app(CandidateFeeCalculator::class);

        return array_values(array_filter(
            array_map(fn (FeeType $type): array => ['type' => $type, 'cents' => $calculator->configuredFeeCents($this->version, $type)], FeeType::cases()),
            fn (array $entry): bool => $entry['cents'] > 0,
        ));
    }

    protected function canWaiveFees(): bool
    {
        return app(VersionRoleAssignmentService::class)->canWaiveFees(Auth::user(), $this->version);
    }

    private function authorizeFeeWaivers(VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canWaiveFees(Auth::user(), $this->version), 403);
    }
}
