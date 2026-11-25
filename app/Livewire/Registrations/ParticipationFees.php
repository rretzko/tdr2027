<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\StartsGroupCheckout;
use App\Enums\FeeType;
use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentTransaction;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Services\CoTeacherAccessService;
use App\Services\FeeReconciliationService;
use App\Services\Payments\CheckoutInProgressGuard;
use App\Services\Payments\PendingCheckoutConfirmer;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A teacher's accepted students after a Version closes: pay the
 * participation fee for any number of them, settle overdue registration
 * fees, and see what's been paid. Reached from Results, since a closed
 * Version leaves Registrations.
 */
#[Layout('components.layouts.app')]
class ParticipationFees extends Component
{
    use StartsGroupCheckout;

    public Version $version;

    /** @var array<int, int|string> */
    public array $selectedCandidateIds = [];

    /** @var array<int, int|string> */
    public array $selectedRegistrationIds = [];

    public function mount(Version $version): void
    {
        self::authorizeTeacher($version, Auth::user()->teacher);

        $this->version = $version;
    }

    /** Same standing as Results: an invitation, or a visible Candidate. */
    public static function authorizeTeacher(Version $version, ?Teacher $teacher): void
    {
        abort_if($teacher === null, 403);
        abort_unless(
            VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists()
                || app(CoTeacherAccessService::class)->candidateQuery($teacher)->where('version_id', $version->id)->exists(),
            403,
        );
        abort_unless($version->collectsParticipationFees(), 403);
    }

    public function payParticipation(): void
    {
        $this->startGroupCheckout($this->selectedCandidateIds, FeeType::Participation);
    }

    public function payRegistration(): void
    {
        $this->startGroupCheckout($this->selectedRegistrationIds, FeeType::Registration);
    }

    public function selectAllUnpaid(FeeReconciliationService $reconciliation): void
    {
        $this->selectedCandidateIds = $reconciliation
            ->rowsForTeacher($this->version, $this->teacher(), FeeType::Participation)
            ->filter(fn (array $row): bool => $row['balanceCents'] > 0 && $row['pendingCents'] === 0)
            ->map(fn (array $row): string => (string) $row['candidate']->id)
            ->values()
            ->all();
    }

    /** Asks the vendor about this teacher's group checkouts still being confirmed. */
    public function confirmPendingPayments(CheckoutInProgressGuard $guard, PendingCheckoutConfirmer $confirmer): void
    {
        foreach ([FeeType::Registration, FeeType::Participation] as $feeType) {
            $pending = $guard->forTeacherGroup($this->version, $this->teacher(), $feeType);

            if ($pending !== null && $confirmer->confirm($pending)) {
                Flux::toast("{$feeType->label()} payment confirmed.", variant: 'success');
            }
        }
    }

    public function render(FeeReconciliationService $reconciliation, CheckoutInProgressGuard $guard): View
    {
        $teacher = $this->teacher();
        $rows = $reconciliation->rowsForTeacher($this->version, $teacher, FeeType::Participation);

        return view('livewire.registrations.participation-fees', [
            'rows' => $rows,
            'totals' => FeeReconciliationService::totals($rows),
            'registrationRows' => $reconciliation->rowsForTeacher($this->version, $teacher, FeeType::Registration)
                ->filter(fn (array $row): bool => $row['balanceCents'] > 0)
                ->values(),
            'canPay' => $this->version->epaymentTeacherReady() && $this->version->participationFeePayableOnline(),
            'confirming' => collect([FeeType::Registration, FeeType::Participation])
                ->contains(fn (FeeType $type): bool => $guard->forTeacherGroup($this->version, $teacher, $type) !== null),
            'unallocatedCents' => (int) PaymentTransaction::where('version_id', $this->version->id)
                ->where('payer_teacher_id', $teacher->id)
                ->where('status', PaymentTransactionStatus::Completed->value)
                ->with('allocations')
                ->get()
                ->filter(fn (PaymentTransaction $t): bool => $t->needsReconciliation())
                ->sum(fn (PaymentTransaction $t): int => $t->unallocatedAmount()),
            'window' => $this->version->participationFeeDate(),
        ]);
    }

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
