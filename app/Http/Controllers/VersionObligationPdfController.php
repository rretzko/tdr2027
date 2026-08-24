<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VersionObligation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class VersionObligationPdfController extends Controller
{
    public function __invoke(Version $version): Response
    {
        $teacher = Auth::user()->teacher;
        abort_if($teacher === null, 403);

        $invitation = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->first();

        abort_if($invitation === null, 404);

        $obligation = $version->obligation;
        abort_if($obligation === null || ! $obligation->isPublished(), 404);

        return Pdf::loadView('pdf.obligation', [
            'version' => $version,
            'obligation' => $obligation,
            'body' => VersionObligation::mergeTokens($obligation->body, $version),
        ])->download(Str::slug($version->name).'-obligations.pdf');
    }
}
