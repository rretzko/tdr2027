<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PdfExportStatus;
use App\Models\Candidate;
use App\Models\CombinedScoresPdfExport;
use App\Models\Version;
use App\Models\VersionInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Teacher-facing download for the Event Manager's optional "Share Results"
 * all-Ensembles public Combined Audition Scores PDF (Version::share_results,
 * see VersionEdit's General tab) — the same S3-stored artifact
 * GenerateCombinedScoresPdfJob already produces for the Tab Room Manager's
 * "Email me the PDF" flow (CombinedAuditionScores::requestAllEnsemblesPdf()),
 * auto-queued instead at Close when share_results is on (CloseAudition::
 * close()). Redirects to a short-lived signed S3 URL rather than streaming
 * the binary through this app, since the PDF already lives on S3.
 */
class SharedScoresPdfController extends Controller
{
    public function __invoke(Version $version): RedirectResponse
    {
        abort_if(! $version->share_results, 404);
        abort_if($version->results_released_at === null, 404);

        $teacher = Auth::user()->teacher;
        abort_if($teacher === null, 403);

        $hasStanding = VersionInvitation::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists()
            || Candidate::where('version_id', $version->id)->where('teacher_id', $teacher->id)->exists();
        abort_unless($hasStanding, 403);

        $export = CombinedScoresPdfExport::where('version_id', $version->id)
            ->where('confidential', false)
            ->first();

        abort_if($export === null || $export->getRawOriginal('status') !== PdfExportStatus::Completed->value, 404);
        abort_if($export->s3_key === null, 404);

        return redirect()->away(Storage::disk('s3')->temporaryUrl($export->s3_key, now()->addMinutes(5)));
    }
}
