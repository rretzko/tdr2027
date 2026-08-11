<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports\TabRoom;

use App\Http\Controllers\Controller;
use App\Models\Version;
use App\Models\VoicePart;
use App\Services\EnsembleCutoffService;
use App\Services\TabRoomReportService;
use App\Services\VersionRoleAssignmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuditionScoresPdfController extends Controller
{
    public function __invoke(Request $request, Version $version, TabRoomReportService $reports, EnsembleCutoffService $cutoffs, VersionRoleAssignmentService $roles): Response
    {
        abort_unless($roles->canManageTabRoomReports(Auth::user(), $version), 403);

        $voicePart = VoicePart::findOrFail($request->query('voice_part_id'));
        $data = $reports->auditionScoreRows($version, $voicePart, $cutoffs);

        return Pdf::loadView('pdf.reports.tab-room.audition-scores', [
            'version' => $version,
            'voicePart' => $voicePart,
            'columns' => $data['columns'],
            'categoryGroups' => $data['categoryGroups'],
            'judgeGroups' => $data['judgeGroups'],
            'rows' => $data['rows'],
        ])->setPaper('a4', 'landscape')->stream("audition-scores-{$voicePart->abbr}-{$version->id}.pdf");
    }
}
