<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Version;
use App\Services\VersionRoleAssignmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class VersionRoomRosterPdfController extends Controller
{
    public function __invoke(Version $version, VersionRoleAssignmentService $roles): Response
    {
        abort_unless($roles->canManageAuditionEnvironment(Auth::user(), $version), 403);

        $rooms = $version->rooms()->with(['scoreCategories', 'voiceParts', 'roomJudges.user'])->get();

        return Pdf::loadView('pdf.version-room-roster', [
            'version' => $version,
            'rooms' => $rooms,
        ])->stream("room-roster-{$version->id}.pdf");
    }
}
