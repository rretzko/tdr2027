<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Enums\CandidateStatus;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Mail\PacketReceivedMail;
use App\Models\Candidate;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\School;
use App\Models\Version;
use App\Models\VersionTeacherPacket;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class ParticipatingSchools extends Component
{
    use ScopesReports;
    use WithPagination;

    /**
     * School/teacher rows per page — rendering every row at once (in
     * duplicate, for the mobile-card and desktop-table layouts) made this
     * report take several seconds to respond on large rosters.
     */
    private const PER_PAGE = 25;

    public Version $version;

    #[Url]
    public string $search = '';

    #[Url]
    public string $packetFilter = '';

    #[Url]
    public string $balanceFilter = '';

    public string $sortColumn = 'school';

    public string $sortDirection = 'asc';

    public ?int $paymentSchoolId = null;

    public ?int $paymentTeacherId = null;

    public string $paymentType = '';

    public string $amount = '';

    public string $referenceNumber = '';

    public string $comments = '';

    public function mount(Version $version, VersionRoleAssignmentService $roles): void
    {
        $this->authorizeReports($version, $roles);

        $this->version = $version;
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPacketFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBalanceFilter(): void
    {
        $this->resetPage();
    }

    public function togglePacket(int $schoolId, int $teacherId): void
    {
        $this->assertPairInScope($schoolId, $teacherId);

        $packet = VersionTeacherPacket::firstOrNew([
            'version_id' => $this->version->id,
            'school_id' => $schoolId,
            'teacher_id' => $teacherId,
        ]);

        if ($packet->isReceived()) {
            $packet->fill(['received_at' => null, 'received_by_user_id' => null])->save();
        } else {
            $packet->fill(['received_at' => now(), 'received_by_user_id' => Auth::id()])->save();
        }
    }

    public function openPayment(int $schoolId, int $teacherId): void
    {
        $this->assertPairInScope($schoolId, $teacherId);

        $this->paymentSchoolId = $schoolId;
        $this->paymentTeacherId = $teacherId;
        $this->paymentType = '';
        $this->amount = '';
        $this->referenceNumber = '';
        $this->comments = '';
        $this->resetErrorBag();
    }

    public function savePayment(): void
    {
        abort_if($this->paymentSchoolId === null || $this->paymentTeacherId === null, 400);
        $this->assertPairInScope($this->paymentSchoolId, $this->paymentTeacherId);

        $modalPaymentTypes = [PaymentType::Check->value, PaymentType::PurchaseOrder->value, PaymentType::Cash->value, PaymentType::Other->value];

        $validated = $this->validate([
            'paymentType' => ['required', 'string', Rule::in($modalPaymentTypes)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'referenceNumber' => ['nullable', 'string', 'max:100'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        // A lump-sum, school/teacher-level payment — genuinely not tied to
        // any specific candidate (that's the whole "haphazard" problem this
        // schema fixes, epayment-integration.md §1.1), so it's created with
        // zero allocations and does NOT count toward any candidate's balance
        // in baseRows() below until reconciled via the allocation screen
        // (§4 step 6).
        PaymentTransaction::create([
            'version_id' => $this->version->id,
            'source' => PaymentSource::Manual,
            'payer_teacher_id' => $this->paymentTeacherId,
            'school_id' => $this->paymentSchoolId,
            'amount' => (int) round(((float) $validated['amount']) * 100),
            'status' => PaymentTransactionStatus::Completed,
            'payment_type' => $validated['paymentType'],
            'reference_number' => $validated['referenceNumber'] !== null && $validated['referenceNumber'] !== '' ? $validated['referenceNumber'] : null,
            'comments' => $validated['comments'] !== null && $validated['comments'] !== '' ? $validated['comments'] : null,
            'recorded_by_user_id' => Auth::id(),
            'paid_at' => now(),
        ]);

        $this->modal('payment-form')->close();

        Flux::toast('Payment recorded. Allocate it to specific candidates for it to count toward their balance.', variant: 'success');
    }

    /**
     * Emails every teacher (within the acting user's county scope) whose
     * packet is received but not yet confirmed, in one batch, then stamps
     * confirmation_sent_at/confirmation_sent_by_user_id on each — see
     * event-version-orientation.md §5.10.
     */
    public function sendConfirmations(): void
    {
        $query = VersionTeacherPacket::where('version_id', $this->version->id)
            ->whereNotNull('received_at')
            ->whereNull('confirmation_sent_at')
            ->with(['teacher.user', 'school']);

        if ($this->reportCountyIds !== null) {
            $query->whereHas('school', fn ($q) => $q->whereIn('county_id', $this->reportCountyIds));
        }

        $pending = $query->get();

        foreach ($pending as $packet) {
            Mail::to($packet->teacher->user->email)->send(new PacketReceivedMail($this->version));

            $packet->update([
                'confirmation_sent_at' => now(),
                'confirmation_sent_by_user_id' => Auth::id(),
            ]);
        }

        Flux::toast($pending->isEmpty() ? 'No confirmations were pending.' : "Sent {$pending->count()} confirmation(s).", variant: 'success');
    }

    public function render(): View
    {
        $allRows = self::filterAndSort(self::baseRows($this->version, $this->reportCountyIds), $this->search, $this->packetFilter, $this->balanceFilter, $this->sortColumn, $this->sortDirection);

        $page = $this->getPage();
        $pageRows = $allRows->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $paginator = new LengthAwarePaginator(
            $pageRows,
            $allRows->count(),
            self::PER_PAGE,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );

        return view('livewire.events.reports.participating-schools', [
            'rows' => $pageRows,
            'rowsPaginator' => $paginator,
        ]);
    }

    /**
     * @param  list<int>|null  $countyIds
     * @return Collection<array-key, mixed>
     */
    public static function baseRows(Version $version, ?array $countyIds): Collection
    {
        $query = Candidate::where('version_id', $version->id)
            ->where('status', CandidateStatus::Registered->value)
            ->with(['teacher.user.phones', 'school']);

        if ($countyIds !== null) {
            $query->whereHas('school', fn ($q) => $q->whereIn('county_id', $countyIds));
        }

        $candidates = $query->get();

        // A brand-new (non-cloned) Version has no version_fees row yet
        // (VersionCloningService only creates one when cloning from a prior
        // Version) — optional() avoids a Larastan false positive that treats
        // fees()->first() as never-null (see feedback_phpstan_quirks).
        // Confirmed 2026-08-14, epayment-integration.md §5 item 1:
        // registration + participation, not registration alone.
        $fees = optional($version->fees()->first());
        $feeCentsPerCandidate = (int) ($fees->registration ?? 0) + (int) ($fees->participation ?? 0);

        $packets = VersionTeacherPacket::where('version_id', $version->id)->get()->keyBy(fn (VersionTeacherPacket $p): string => "{$p->school_id}:{$p->teacher_id}");

        $candidateIdsByPair = $candidates->groupBy(fn (Candidate $c): string => "{$c->school_id}:{$c->teacher_id}")
            ->map(fn (Collection $group): array => $group->pluck('id')->all());

        // "Paid" is sum(payment_allocations.amount) for Completed
        // transactions only, resolved via each allocation's own candidate —
        // never payment_transactions.school_id/payer_teacher_id, which is
        // only a creation-time snapshot (epayment-integration.md §1.1). A
        // lump-sum payment recorded via savePayment() above intentionally
        // does NOT count here until it's been allocated to specific
        // candidates through the reconciliation screen (§4 step 6) — this
        // is a deliberate behavior change from the old teacher_payments
        // total, which counted immediately on entry.
        $paidByCandidateId = PaymentAllocation::whereIn('candidate_id', $candidates->pluck('id'))
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', PaymentTransactionStatus::Completed->value))
            ->get()
            ->groupBy('candidate_id')
            ->map(fn (Collection $g): int => (int) $g->sum('amount'));

        return $candidates->groupBy(fn (Candidate $c): string => "{$c->school_id}:{$c->teacher_id}")
            ->map(function (Collection $group, string $key) use ($feeCentsPerCandidate, $packets, $candidateIdsByPair, $paidByCandidateId): array {
                $first = $group->first();
                $count = $group->count();
                $dueCents = $count * $feeCentsPerCandidate;

                $paidCents = 0;
                foreach ($candidateIdsByPair->get($key, []) as $candidateId) {
                    $paidCents += $paidByCandidateId->get($candidateId, 0);
                }

                return [
                    'key' => $key,
                    'school' => $first->school,
                    'teacher' => $first->teacher,
                    'count' => $count,
                    'packet' => $packets->get($key),
                    'dueCents' => $dueCents,
                    'paidCents' => $paidCents,
                    'balanceCents' => $dueCents - $paidCents,
                ];
            });
    }

    /**
     * @param  Collection<array-key, mixed>  $rows
     * @return Collection<array-key, mixed>
     */
    public static function filterAndSort(Collection $rows, string $search, string $packetFilter, string $balanceFilter, string $sortColumn, string $sortDirection): Collection
    {
        $search = mb_strtolower(trim($search));

        if ($search !== '') {
            $rows = $rows->filter(function (array $row) use ($search): bool {
                $user = $row['teacher']->user;
                $school = $row['school'];

                return str_contains(mb_strtolower($user->first_name), $search)
                    || str_contains(mb_strtolower($user->last_name), $search)
                    || ($school instanceof School && str_contains(mb_strtolower($school->name), $search));
            });
        }

        if ($packetFilter === 'outstanding') {
            $rows = $rows->filter(fn (array $row): bool => ! ($row['packet']?->isReceived() ?? false));
        }

        if ($balanceFilter !== '') {
            $rows = $rows->filter(function (array $row) use ($balanceFilter): bool {
                return match ($balanceFilter) {
                    'due' => $row['balanceCents'] > 0,
                    'overpayment' => $row['balanceCents'] < 0,
                    'none' => $row['balanceCents'] === 0,
                    default => true,
                };
            });
        }

        $sortValue = fn (array $row): int|string => match ($sortColumn) {
            'teacher' => mb_strtolower($row['teacher']->user->last_name),
            'count' => $row['count'],
            'due' => $row['dueCents'],
            'balance' => $row['balanceCents'],
            default => mb_strtolower($row['school']->name),
        };

        $rows = $sortDirection === 'desc'
            ? $rows->sortByDesc($sortValue)
            : $rows->sortBy($sortValue);

        return $rows->values()->keyBy('key');
    }

    private function assertPairInScope(int $schoolId, int $teacherId): void
    {
        $query = Candidate::where('version_id', $this->version->id)
            ->where('school_id', $schoolId)
            ->where('teacher_id', $teacherId)
            ->where('status', CandidateStatus::Registered->value);

        if ($this->reportCountyIds !== null) {
            $query->whereHas('school', fn ($q) => $q->whereIn('county_id', $this->reportCountyIds));
        }

        abort_unless($query->exists(), 403);
    }
}
