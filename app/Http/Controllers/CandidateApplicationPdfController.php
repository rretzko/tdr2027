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

        // Owning teacher or owning student (studentfolder-module.md §5.6) —
        // the PDF download is a read-only convenience available to both
        // roles regardless of status/obligations state.
        $teacher = Auth::user()->teacher;
        $isOwningTeacher = $teacher !== null && $candidate->teacher_id === $teacher->id;

        $student = Auth::user()->student;
        $isOwningStudent = $student !== null && $candidate->student_id === $student->id;

        abort_if(! $isOwningTeacher && ! $isOwningStudent, 403);

        $application = $version->candidateApplication;
        abort_if($application === null || ! $application->isPublished(), 404);

        $data = CandidateApplicationData::fromCandidate($candidate->load([
            'student.user.pronoun', 'student.emergencyContacts', 'teacher.user', 'school', 'voicePart', 'version.fees', 'version.dates', 'version.event.organization',
        ]));

        $studentBody = VersionApplication::mergeTokens($application->student_endorsement_body, $data);
        $parentBody = VersionApplication::mergeTokens($application->parent_endorsement_body, $data);
        $teacherBody = $application->teacher_principal_endorsement_body !== null
            ? VersionApplication::mergeTokens($application->teacher_principal_endorsement_body, $data)
            : null;
        $scheduleBody = $application->schedule_body !== null
            ? VersionApplication::mergeTokens($application->schedule_body, $data)
            : null;
        $policiesBody = $application->policies_body !== null
            ? VersionApplication::mergeTokens($application->policies_body, $data)
            : null;

        return Pdf::loadView('pdf.candidate-application', [
            'version' => $version,
            'data' => $data,
            'studentBody' => $studentBody,
            'parentBody' => $parentBody,
            'teacherBody' => $teacherBody,
            'scheduleBody' => $scheduleBody,
            'policiesBody' => $policiesBody,
            'showTeacherSection' => $version->getRawOriginal('application_type') === ApplicationType::Pdf->value,
            // Rendered as a simulated signature in the shared document
            // partial (product-owner direction, 2026-08-18) — null for
            // Pdf-mode Versions, which have no self-attest timestamp.
            'candidateSignedAt' => $candidate->application_candidate_signed_at,
            'parentSignedAt' => $candidate->application_parent_signed_at,
        ])->download("application-{$candidate->ref}.pdf");
    }
}
