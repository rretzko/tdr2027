<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Livewire\Events\Reports\RegistrationCards;
use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Placeholder output only (see event-version-orientation.md §5.10) — no
 * real room<->Candidate assignment exists yet, and no in-person audition is
 * scheduled this season.
 */
class RegistrationCardsPdfController extends Controller
{
    public function __invoke(Request $request, Version $version, VersionRoleAssignmentService $roles): Response
    {
        abort_unless($roles->canManageReports(Auth::user(), $version), 403);

        $countyIds = $roles->reportCountyIds(Auth::user(), $version);
        $candidates = RegistrationCards::applyFilters(
            RegistrationCards::scopedCandidates($version, $countyIds),
            (string) $request->query('candidateIdFilter', ''),
            (string) $request->query('schoolFilter', ''),
            (string) $request->query('voicePartFilter', ''),
        );

        return Pdf::loadView('pdf.reports.registration-cards', [
            'version' => $version,
            'candidates' => $candidates,
        ])->stream("registration-cards-{$version->id}.pdf");
    }
}
