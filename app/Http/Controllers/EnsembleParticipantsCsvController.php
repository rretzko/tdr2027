<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ensemble;
use App\Models\Version;
use App\Models\VersionEnsembleParticipantExport;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads one Ensemble's current Ensemble Participants CSV from S3
 * (EnsembleParticipantsCsvService). Same access as Reports → Downloads.
 */
class EnsembleParticipantsCsvController extends Controller
{
    public function __invoke(Version $version, Ensemble $ensemble, VersionRoleAssignmentService $roles): StreamedResponse
    {
        abort_unless($roles->canDownloadVersionData(Auth::user(), $version), 403);

        $export = VersionEnsembleParticipantExport::where('version_id', $version->id)->where('ensemble_id', $ensemble->id)->first();
        abort_if($export === null || ! Storage::disk('s3')->exists($export->s3_key), 404);

        return Storage::disk('s3')->download($export->s3_key, $export->filename, ['Content-Type' => 'text/csv']);
    }
}
