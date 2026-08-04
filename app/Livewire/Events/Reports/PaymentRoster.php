<?php

declare(strict_types=1);

namespace App\Livewire\Events\Reports;

use App\Concerns\ScopesReports;
use App\Models\CandidatePayment;
use App\Models\TeacherPayment;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PaymentRoster extends Component
{
    use ScopesReports;

    public Version $version;

    #[Url]
    public string $search = '';

    #[Url]
    public string $schoolFilter = '';

    #[Url]
    public string $paymentTypeFilter = '';

    public string $sortColumn = 'school';

    public string $sortDirection = 'asc';

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
    }

    public function render(): View
    {
        $allRows = self::baseRows($this->version, $this->reportCountyIds);

        return view('livewire.events.reports.payment-roster', [
            'rows' => self::filterAndSort($allRows, $this->search, $this->schoolFilter, $this->paymentTypeFilter, $this->sortColumn, $this->sortDirection),
            'schoolOptions' => $allRows->pluck('schoolName')->filter()->unique()->sort()->values(),
        ]);
    }

    /**
     * @param  list<int>|null  $countyIds
     * @return Collection<int, mixed>
     */
    public static function baseRows(Version $version, ?array $countyIds): Collection
    {
        $teacherPaymentQuery = TeacherPayment::where('version_id', $version->id)->with(['school', 'teacher.user']);

        if ($countyIds !== null) {
            $teacherPaymentQuery->whereHas('school', fn ($q) => $q->whereIn('county_id', $countyIds));
        }

        $teacherRows = $teacherPaymentQuery->get()->map(fn (TeacherPayment $payment): array => [
            'source' => 'teacher:'.$payment->id,
            'schoolName' => $payment->school->name ?? null,
            'teacherName' => $payment->teacher->user->name,
            'candidateName' => null,
            'paymentType' => $payment->payment_type,
            'amountCents' => $payment->amount,
            'referenceNumber' => $payment->reference_number,
            'comments' => $payment->comments,
        ]);

        $candidatePaymentQuery = CandidatePayment::where('version_id', $version->id)->with(['candidate.school', 'candidate.teacher.user', 'candidate.student.user']);

        if ($countyIds !== null) {
            $candidatePaymentQuery->whereHas('candidate.school', fn ($q) => $q->whereIn('county_id', $countyIds));
        }

        $candidateRows = $candidatePaymentQuery->get()->map(fn (CandidatePayment $payment): array => [
            'source' => 'candidate:'.$payment->id,
            'schoolName' => $payment->candidate->school->name ?? null,
            'teacherName' => $payment->candidate->teacher->user->name,
            'candidateName' => $payment->candidate->student->user->name,
            'paymentType' => $payment->payment_type,
            'amountCents' => $payment->amount,
            'referenceNumber' => $payment->reference_number,
            'comments' => $payment->comments,
        ]);

        return $teacherRows->concat($candidateRows)->values();
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    public static function filterAndSort(Collection $rows, string $search, string $schoolFilter, string $paymentTypeFilter, string $sortColumn, string $sortDirection): Collection
    {
        $search = mb_strtolower(trim($search));

        if ($search !== '') {
            $rows = $rows->filter(fn (array $row): bool => str_contains(mb_strtolower((string) $row['schoolName']), $search)
                || str_contains(mb_strtolower($row['teacherName']), $search)
                || str_contains(mb_strtolower((string) $row['candidateName']), $search));
        }

        if ($schoolFilter !== '') {
            $rows = $rows->filter(fn (array $row): bool => $row['schoolName'] === $schoolFilter);
        }

        if ($paymentTypeFilter !== '') {
            $rows = $rows->filter(fn (array $row): bool => $row['paymentType']->value === $paymentTypeFilter);
        }

        $sortValue = fn (array $row): string => match ($sortColumn) {
            'teacher' => mb_strtolower($row['teacherName']),
            'candidate' => mb_strtolower((string) $row['candidateName']),
            default => mb_strtolower((string) $row['schoolName']),
        };

        $rows = $sortDirection === 'desc'
            ? $rows->sortByDesc($sortValue)
            : $rows->sortBy($sortValue);

        return $rows->values();
    }
}
