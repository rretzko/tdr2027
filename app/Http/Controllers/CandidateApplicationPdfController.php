<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ApplicationType;
use App\Models\Candidate;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Support\CandidateApplicationData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class CandidateApplicationPdfController extends Controller
{
    public function __invoke(Version $version, Candidate $candidate): Response
    {
        abort_if($candidate->version_id !== $version->id, 404);

        $teacher = Auth::user()->teacher;
        abort_if($teacher === null || $candidate->teacher_id !== $teacher->id, 403);

        $application = $version->candidateApplication;
        abort_if($application === null || ! $application->isPublished(), 404);

        $data = CandidateApplicationData::fromCandidate($candidate->load([
            'student.user', 'student.emergencyContacts', 'teacher.user', 'school', 'voicePart', 'version.fees', 'version.event.organization',
        ]));

        $studentBody = VersionApplication::mergeTokens($application->student_endorsement_body, $data);
        $parentBody = VersionApplication::mergeTokens($application->parent_endorsement_body, $data);
        $teacherBody = $application->teacher_principal_endorsement_body !== null
            ? VersionApplication::mergeTokens($application->teacher_principal_endorsement_body, $data)
            : null;

        return Pdf::loadView('pdf.candidate-application', [
            'version' => $version,
            'data' => $data,
            'studentBody' => $studentBody,
            'parentBody' => $parentBody,
            'teacherBody' => $teacherBody,
            'showTeacherSection' => $version->getRawOriginal('application_type') === ApplicationType::Pdf->value,
        ])->download("application-{$candidate->ref}.pdf");
    }
}
