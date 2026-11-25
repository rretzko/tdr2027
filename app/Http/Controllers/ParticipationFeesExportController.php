<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FeeType;
use App\Livewire\Registrations\ParticipationFees;
use App\Models\Version;
use App\Services\FeeReconciliationService;
use App\Support\Reports\CsvExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParticipationFeesExportController extends Controller
{
    public function __invoke(Version $version, string $format, FeeReconciliationService $reconciliation): Response|StreamedResponse
    {
        $teacher = Auth::user()->teacher;
        ParticipationFees::authorizeTeacher($version, $teacher);

        $rows = $reconciliation->rowsForTeacher($version, $teacher, FeeType::Participation);

        if ($format === 'csv') {
            return CsvExport::stream("participation-fees-{$version->id}.csv", FeeReconciliationService::STUDENT_HEADERS, FeeReconciliationService::studentCsvRows($rows));
        }

        return Pdf::loadView('pdf.fee-reconciliation', [
            'title' => 'Participation Fees',
            'version' => $version,
            'subheading' => $teacher->user->name,
            'rows' => $rows,
            'totals' => FeeReconciliationService::totals($rows),
        ])->download("participation-fees-{$version->id}.pdf");
    }
}
