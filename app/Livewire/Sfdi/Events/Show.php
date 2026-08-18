<?php

declare(strict_types=1);

namespace App\Livewire\Sfdi\Events;

use App\Concerns\HasCandidateChecklist;
use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\CandidateUploadStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\UploadType;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\Recording;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionInvitation;
use App\Models\VersionPitchFile;
use App\Models\VersionUploadFile;
use App\Services\CandidateService;
use App\Services\RecordingReviewService;
use App\Support\CandidateApplicationData;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Student-facing per-Candidate page (studentfolder-module.md §5.2/§5.3) —
 * the read-only Candidate-requirements checklist (build-order step 3) plus
 * step 4's Registration-requirements write actions: voice part, program
 * name, withdrawal, and Recordings. Also hosts Pitch Files (§5.5, step 5)
 * as a modal rather than a separate page — unlike the teacher's own
 * `Registrations\PitchFiles`, which stays a full page since it needs a
 * free voice-part filter across the whole roster; a candidate only ever
 * needs their own single voice part, so a modal keeps them on this page
 * (product-owner direction, 2026-08-18). Also hosts the Candidate
 * Application (§5.6, step 6): a View modal (mirroring `CandidateDetail`'s,
 * rendering the same shared `candidate-application.document` partial) plus,
 * `EApplication`-mode only, the two self-attestation checkboxes — `Pdf`-mode
 * `application_certified_at` stays teacher-only, no student-facing toggle
 * for it. The PDF download link/View modal are read-only and available
 * regardless of the two gates below; only the two checkboxes are gated.
 *
 * Two gates apply uniformly to every write action below (§5.3, §0 second
 * pass, 2026-08-18):
 * - Status lock (isLocked()): read-only once the candidate is no longer in
 *   an active registration state.
 * - Obligations iron gate (isObligationsBlocked()): read-only while the
 *   candidate's own teacher has an unaccepted/rejected obligations response
 *   for this Version. Unlike the teacher-side GuardsAcceptedObligations,
 *   this never redirects — there is no page for a student to "go accept
 *   obligations," since only the teacher can do that — it just renders an
 *   informational block in place of the write actions.
 */
#[Layout('components.layouts.app')]
class Show extends Component
{
    use HasCandidateChecklist;
    use WithFileUploads;

    private const CANDIDATE_REQUIREMENT_LABELS = [
        'Emergency contact',
        'Birthday',
        'Height',
        'Home address',
        'Shirt size',
    ];

    public Candidate $candidate;

    public Version $version;

    public string $edit_voice_part_id = '';

    public string $edit_program_name = '';

    public ?int $uploadingVersionUploadFileId = null;

    public $newRecordingFile = null;

    /**
     * Ownership is a straight equality check, not a Coteacher-style shared
     * roster (studentfolder-module.md §7 — "there is no co-student analog").
     */
    public function mount(Candidate $candidate): void
    {
        $student = Auth::user()->student;

        abort_if($student === null, 403);
        abort_unless($candidate->student_id === $student->id, 404);

        $this->candidate = $candidate->load([
            'version.event',
            'version.obligation',
            'teacher.user',
            'student.homeAddress',
            'student.emergencyContacts',
            'voicePart',
            'uploadFiles',
        ]);
        $this->version = $this->candidate->version;
    }

    /**
     * Pitch Files (§5.5) is read-only reference material with nothing to
     * reset on open, so — like the teacher-side "View Application" modal —
     * this is just a trigger; the content itself is computed in render()
     * and already sitting in the DOM, Flux toggles its visibility.
     */
    public function viewPitchFiles(): void
    {
        $this->modal('pitch-files')->show();
    }

    public function viewApplication(): void
    {
        $this->modal('view-application')->show();
    }

    public function toggleApplicationCandidateSigned(CandidateService $candidates): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        if ($this->version->getRawOriginal('application_type') !== ApplicationType::EApplication->value) {
            return;
        }

        $this->candidate->update([
            'application_candidate_signed_at' => $this->candidate->application_candidate_signed_at === null ? now() : null,
        ]);

        $candidates->recalculateStatus($this->candidate->refresh(), $this->checklistDefs($this->version));

        Flux::toast('Candidate signature status updated.');
    }

    public function toggleApplicationParentSigned(CandidateService $candidates): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        if ($this->version->getRawOriginal('application_type') !== ApplicationType::EApplication->value) {
            return;
        }

        $this->candidate->update([
            'application_parent_signed_at' => $this->candidate->application_parent_signed_at === null ? now() : null,
        ]);

        $candidates->recalculateStatus($this->candidate->refresh(), $this->checklistDefs($this->version));

        Flux::toast('Parent signature status updated.');
    }

    public function editVoicePart(): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        $this->edit_voice_part_id = (string) $this->candidate->voice_part_id;
        $this->resetErrorBag('edit_voice_part_id');
        $this->modal('edit-voice-part')->show();
    }

    public function saveVoicePart(CandidateService $candidates): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        $this->validate([
            'edit_voice_part_id' => ['required', 'integer', Rule::in($this->version->availableVoiceParts()->pluck('id')->all())],
        ]);

        $this->candidate->update(['voice_part_id' => (int) $this->edit_voice_part_id]);

        $candidates->recalculateStatus(
            $this->candidate->refresh(),
            $this->checklistDefs($this->version),
        );

        $this->modal('edit-voice-part')->close();

        Flux::toast('Voice part saved.');
    }

    public function editProgramName(): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        $this->edit_program_name = $this->candidate->program_name;
        $this->resetErrorBag('edit_program_name');
        $this->modal('edit-program-name')->show();
    }

    public function saveProgramName(CandidateService $candidates): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        $this->validate(['edit_program_name' => ['required', 'string', 'max:255']]);

        $this->candidate->update(['program_name' => $this->edit_program_name]);

        $candidates->recalculateStatus(
            $this->candidate->refresh(),
            $this->checklistDefs($this->version),
        );

        $this->modal('edit-program-name')->close();

        Flux::toast('Program name saved.');
    }

    public function withdraw(CandidateService $candidates): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        $candidates->withdrawByCandidate($this->candidate);

        Flux::toast('You have withdrawn from this Event.');

        $this->redirect(route('sfdi.events.index'), navigate: true);
    }

    public function uploadRecording(int $versionUploadFileId): void
    {
        abort_if($this->writeActionsBlocked(), 403);
        abort_if($this->recordingLocked($versionUploadFileId), 403);

        $this->uploadingVersionUploadFileId = $versionUploadFileId;
        $this->newRecordingFile = null;
        $this->resetErrorBag('newRecordingFile');
        $this->modal('upload-recording')->show();
    }

    public function saveRecording(CandidateService $candidates, RecordingReviewService $review): void
    {
        abort_if($this->writeActionsBlocked(), 403);
        abort_if($this->recordingLocked($this->uploadingVersionUploadFileId), 403);

        $this->validate([
            // extensions:, not mimes: — a real .m4a file's detected MIME
            // type is frequently video/mp4 (it shares the ISO-BMFF/MP4
            // container with video), which mimes:'s MIME-to-extension
            // round-trip rejects even though the extension is legitimate.
            // extensions: checks the file's actual extension directly,
            // per Laravel's own docs recommendation for exactly this case.
            'newRecordingFile' => ['required', 'file', 'extensions:'.$this->allowedRecordingExtensions(), 'max:51200'],
        ]);

        $slot = VersionUploadFile::where('id', $this->uploadingVersionUploadFileId)
            ->where('version_id', $this->version->id)
            ->firstOrFail();

        $existing = CandidateUploadFile::where('candidate_id', $this->candidate->id)
            ->where('version_upload_file_id', $this->uploadingVersionUploadFileId)
            ->first();

        $file = $this->newRecordingFile;
        $path = $file->store("candidateUploads/{$this->candidate->id}", 's3');
        $originalFilename = $file->getClientOriginalName();

        // The upload itself is persisted first, deliberately ahead of
        // RecordingReviewService::evaluate() below — that call runs getID3
        // against real (non-fake) audio, which has been observed to crash
        // the PHP worker outright on this Windows/Apache dev setup (a
        // process-level access violation, not a catchable PHP exception —
        // see storage/app private disk crash notes, 2026-08-18). Getting
        // the file and its row committed before that call runs means a
        // crash there costs the flag/duration assist, not the upload
        // itself — matching this service's own "never blocks" design
        // intent (its docblock: "never rejects or blocks... a false flag
        // just costs a teacher a second look").
        if ($existing !== null) {
            $previousUrl = $existing->url;
            $existing->update([
                'url' => $path,
                'uploaded_by_user_id' => Auth::id(),
                'original_filename' => $originalFilename,
                'duration_seconds' => null,
                'flagged_at' => null,
                'flag_reason' => null,
                'status' => CandidateUploadStatus::Pending->value,
                'uploaded_at' => now(),
                'decided_at' => null,
                'decided_by_user_id' => null,
            ]);
            Storage::disk('s3')->delete($previousUrl);
            $upload = $existing;
        } else {
            $upload = CandidateUploadFile::create([
                'candidate_id' => $this->candidate->id,
                'version_upload_file_id' => $this->uploadingVersionUploadFileId,
                'uploaded_by_user_id' => Auth::id(),
                'url' => $path,
                'original_filename' => $originalFilename,
                'status' => CandidateUploadStatus::Pending->value,
                'uploaded_at' => now(),
            ]);
        }

        $this->uploadingVersionUploadFileId = null;
        $this->newRecordingFile = null;

        $candidates->recalculateStatus(
            $this->candidate->load('uploadFiles')->refresh(),
            $this->checklistDefs($this->version),
        );

        // Best-effort only, after the upload itself is already committed
        // above — see the comment there. A crash inside evaluate() (see
        // RecordingReviewService::extractDurationSeconds()'s own SAPI
        // guard) costs only this flag/duration data, never the upload.
        $reviewResult = $review->evaluate($file, $slot, $this->version->uploadFiles);
        $upload->update($reviewResult);

        $this->modal('upload-recording')->close();

        Flux::toast(
            $reviewResult['flagged_at'] !== null
                ? 'Recording uploaded — your teacher will review the flag noted below.'
                : 'Recording uploaded.',
            variant: $reviewResult['flagged_at'] !== null ? 'warning' : 'success',
        );
    }

    /**
     * Student-initiated delete of a not-yet-approved recording — clears the
     * slot without requiring an immediate replacement (§0 second pass).
     * Deliberately not named "reject" (that stays the teacher's own
     * rejectRecording(), a review verdict) even though the mechanics match.
     */
    public function deleteRecording(int $candidateUploadFileId, CandidateService $candidates): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        $upload = CandidateUploadFile::where('id', $candidateUploadFileId)
            ->where('candidate_id', $this->candidate->id)
            ->first();

        abort_if($upload === null, 404);
        abort_if($upload->getRawOriginal('status') === CandidateUploadStatus::Approved->value, 403);

        // Defensive — an Approved recording may already be synced into
        // `recordings`, but a Pending one (the only status this action ever
        // reaches) never is, since that sync only happens at approval time.
        Recording::where('version_id', $this->candidate->version_id)
            ->where('candidate_id', $this->candidate->id)
            ->where('file_type', $upload->versionUploadFile->name)
            ->delete();

        Storage::disk('s3')->delete($upload->url);
        $upload->delete();

        $candidates->recalculateStatus(
            $this->candidate->load('uploadFiles')->refresh(),
            $this->checklistDefs($this->version),
        );

        Flux::toast('Recording removed.');
    }

    public function render(): View
    {
        $checklistDefs = array_values(array_filter(
            $this->checklistDefs($this->version),
            fn (array $def): bool => in_array($def['label'], self::CANDIDATE_REQUIREMENT_LABELS, true),
        ));

        $pitchFilesVisible = in_array(
            $this->version->getRawOriginal('pitch_file_visibility'),
            [PitchFileVisibility::Both->value, PitchFileVisibility::Candidate->value],
            true,
        );

        return view('livewire.sfdi.events.show', [
            'checklistDefs' => $checklistDefs,
            'writeActionsBlocked' => $this->writeActionsBlocked(),
            'obligationsBlocked' => $this->isObligationsBlocked(),
            'uploadSlots' => $this->version->getRawOriginal('audition_type') === AuditionType::Remote->value
                ? $this->version->uploadFiles
                : collect(),
            'candidateUploads' => $this->candidate->uploadFiles->keyBy('version_upload_file_id'),
            'pitchFilesVisible' => $pitchFilesVisible,
            'pitchFiles' => $pitchFilesVisible ? $this->matchingPitchFiles() : new Collection,
            ...$this->applicationDocumentView(),
        ]);
    }

    /**
     * Combines both §5.3 gates — used by every write action's guard and by
     * the view to decide whether to render actions or the informational
     * block in their place.
     */
    private function writeActionsBlocked(): bool
    {
        return $this->isLocked() || $this->isObligationsBlocked();
    }

    private function isLocked(): bool
    {
        // getRawOriginal(), not the magic-cast property — Larastan can't
        // reliably infer the enum cast through this model's method-based
        // casts() return (cataloged PHPStan-quirks memory).
        $status = CandidateStatus::from((string) $this->candidate->getRawOriginal('status'));

        return ! in_array($status, CandidateStatus::registrationStates(), true);
    }

    /**
     * Replicates GuardsAcceptedObligations::redirectUnlessObligationsAccepted()'s
     * condition without its redirect — that trait sends the caller to the
     * teacher-only obligations-response page, which would break here (no
     * Auth::user()->teacher to resolve on a student's session).
     */
    private function isObligationsBlocked(): bool
    {
        $obligation = $this->version->obligation;

        if (! $obligation?->isPublished()) {
            return false;
        }

        $invitation = VersionInvitation::with('obligationResponse')
            ->where('version_id', $this->version->id)
            ->where('teacher_id', $this->candidate->teacher_id)
            ->first();

        return $invitation?->obligationResponse?->isAccepted() !== true;
    }

    /**
     * Per-slot gate, independent of the two candidate-level gates above: an
     * Approved recording is replay-only regardless of anything else.
     */
    private function recordingLocked(?int $versionUploadFileId): bool
    {
        if ($versionUploadFileId === null) {
            return true;
        }

        $upload = $this->candidate->uploadFiles->firstWhere('version_upload_file_id', $versionUploadFileId);

        return $upload !== null && $upload->getRawOriginal('status') === CandidateUploadStatus::Approved->value;
    }

    private function allowedRecordingExtensions(): string
    {
        return match ($this->version->getRawOriginal('upload_type')) {
            UploadType::Video->value => 'mp4,mov',
            default => 'mp3,wav,m4a',
        };
    }

    /**
     * Pitch files matching the candidate's own voice part, plus the seeded
     * 'ALL' voice part — no filter UI needed here (contrast with the
     * teacher's free voice-part dropdown on `Registrations\PitchFiles`): a
     * candidate has exactly one voice part per Version, so the list is
     * always scoped to it rather than offered as a choice.
     *
     * @return Collection<int, VersionPitchFile>
     */
    private function matchingPitchFiles(): Collection
    {
        return $this->version->pitchFiles()
            ->with('voicePart')
            ->get()
            ->filter(fn (VersionPitchFile $pitchFile): bool => $pitchFile->voice_part_id === $this->candidate->voice_part_id
                || $pitchFile->voicePart->abbr === 'ALL')
            ->sortBy(fn (VersionPitchFile $pitchFile): array => [$pitchFile->voicePart->sort_order, $pitchFile->order_by])
            ->values();
    }

    /**
     * Identical computation to CandidateDetail::applicationDocumentView()
     * (§5.6) — same shared merge-token/document-partial rendering, so the
     * student's View modal and the actual PDF download can never drift
     * from what the teacher sees.
     *
     * @return array{application: VersionApplication|null, applicationDoc: array{data: CandidateApplicationData, studentBody: string, parentBody: string, teacherBody: ?string, scheduleBody: ?string, policiesBody: ?string, showTeacherSection: bool, candidateSignedAt: ?Carbon, parentSignedAt: ?Carbon}|null}
     */
    private function applicationDocumentView(): array
    {
        $application = $this->version->candidateApplication;

        if ($application === null || ! $application->isPublished()) {
            return ['application' => null, 'applicationDoc' => null];
        }

        $data = CandidateApplicationData::fromCandidate($this->candidate->load([
            'student.user.pronoun', 'student.emergencyContacts', 'teacher.user', 'school', 'voicePart', 'version.fees', 'version.dates', 'version.event.organization',
        ]));

        return [
            'application' => $application,
            'applicationDoc' => [
                'data' => $data,
                'studentBody' => VersionApplication::mergeTokens($application->student_endorsement_body, $data),
                'parentBody' => VersionApplication::mergeTokens($application->parent_endorsement_body, $data),
                'teacherBody' => $application->teacher_principal_endorsement_body !== null
                    ? VersionApplication::mergeTokens($application->teacher_principal_endorsement_body, $data)
                    : null,
                'scheduleBody' => $application->schedule_body !== null
                    ? VersionApplication::mergeTokens($application->schedule_body, $data)
                    : null,
                'policiesBody' => $application->policies_body !== null
                    ? VersionApplication::mergeTokens($application->policies_body, $data)
                    : null,
                'showTeacherSection' => $this->version->getRawOriginal('application_type') === ApplicationType::Pdf->value,
                // Rendered as a simulated signature in the shared document
                // partial (per product-owner direction, 2026-08-18) — null
                // for Pdf-mode Versions, which have no self-attest timestamp
                // to show (the physical form is certified by the teacher as
                // a single event, not per-signer).
                // Carbon::make(), not the magic-cast property directly —
                // Larastan infers this model's datetime casts as plain
                // string|null through its method-based casts() return
                // (cataloged PHPStan-quirks memory); Carbon::make() is a
                // no-op at runtime (the value is already a Carbon instance)
                // but gives the type checker what it needs.
                'candidateSignedAt' => Carbon::make($this->candidate->application_candidate_signed_at),
                'parentSignedAt' => Carbon::make($this->candidate->application_parent_signed_at),
            ],
        ];
    }
}
