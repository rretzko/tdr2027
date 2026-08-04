<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use App\Support\Reports\CsvExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Placeholder output only (see event-version-orientation.md §5.10) — no
 * real room<->Candidate assignment exists yet, and no in-person audition is
 * scheduled this season. Version-wide rather than per-room, since a
 * placeholder's content doesn't meaningfully differ by room.
 */
class AdjudicationBackupExportController extends Controller
{
    public function __invoke(Version $version, string $type, VersionRoleAssignmentService $roles): Response|StreamedResponse
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $rooms = $version->rooms;

        if ($type === 'csv') {
            return CsvExport::stream(
                "adjudication-backup-{$version->id}.csv",
                ['Room', 'Note'],
                $rooms->map(fn ($room): array => [$room->name, 'No in-person audition scheduled for the current season.']),
            );
        }

        $label = $type === 'checklist' ? 'Monitor Checklist' : 'Score Sheet';

        return Pdf::loadView('pdf.reports.adjudication-backup', [
            'version' => $version,
            'rooms' => $rooms,
            'label' => $label,
        ])->stream("adjudication-backup-{$type}-{$version->id}.pdf");
    }
}
