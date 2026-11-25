<?php

declare(strict_types=1);

namespace App\Livewire\Sfdi\Events;

use App\Concerns\HasCandidateChecklist;
use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\CandidateUploadStatus;
use App\Enums\FeeType;
use App\Enums\PitchFileVisibility;
use App\Enums\UploadType;
use App\Enums\VoicePartRecordingDecision;
use App\Exceptions\VoicePartChangeRefusedException;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\PaymentTransaction;
use App\Models\Recording;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionInvitation;
use App\Models\VersionPitchFile;
use App\Models\VersionTeacherEpaymentOptIn;
use App\Models\VersionUploadFile;
use App\Models\VoicePart;
use App\Services\CandidateFeeCalculator;
use App\Services\CandidateFeeLedger;
use App\Services\CandidateService;
use App\Services\Payments\CheckoutInProgressGuard;
use App\Services\Payments\PaymentGatewayFactory;
use App\Services\Payments\PendingCheckoutConfirmer;
use App\Services\RecordingReviewService;
use App\Services\VoicePartChangeService;
use App\Support\CandidateApplicationData;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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
 * Three gates apply uniformly to every write action below (§5.3, §0 second
 * pass, 2026-08-18; window gate added 2026-10-26):
 * - Candidate window (Version::candidateWindowIsOpen()): read-only before
 *   the Candidate date's start_at and after its end_at. Pitch Files and
 *   student Registration fee payment use the longer
 *   Version::candidateReferenceWindowIsOpen() instead (through Final
 *   Teacher Changes).
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

    /** The part named in the open upload modal; a change since then refuses the save. */
    #[Locked]
    public ?int $uploadVoicePartId = null;

    public bool $recording_part_attested = false;

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
        if (! $this->version->candidateReferenceWindowIsOpen()) {
            Flux::toast(
                $this->version->candidateWindowHasOpened()
                    ? 'Pitch Files for this Event are no longer available.'
                    : 'Pitch Files will be available once student registration opens.',
                variant: 'warning',
            );

            return;
        }

        if ($this->confirmationRequired()) {
            $this->modal('confirm-voice-part')->show();

            return;
        }

        $this->modal('pitch-files')->show();
    }

    /**
     * The Application section (View +, in EApplication mode, the signature
     * checkboxes it hosts) is disabled until every currently-required
     * Candidate Requirement is met — a new gate on top of §5.3's existing
     * two (status lock, obligations), scoped to this one section rather than
     * every write action on the page. Deliberately excludes application-
     * signature items themselves (checklistDefs() only adds those once the
     * Application is published, and CANDIDATE_REQUIREMENT_LABELS never
     * includes them) — otherwise meeting this gate would depend on the very
     * section it gates.
     */
    public function viewApplication(): void
    {
        abort_if(! $this->candidateRequirementsMet(), 403);

        $this->modal('view-application')->show();
    }

    public function toggleApplicationCandidateSigned(CandidateService $candidates): void
    {
        abort_if($this->writeActionsBlocked(), 403);
        abort_if(! $this->candidateRequirementsMet(), 403);

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
        abort_if(! $this->candidateRequirementsMet(), 403);

        if ($this->version->getRawOriginal('application_type') !== ApplicationType::EApplication->value) {
            return;
        }

        $this->candidate->update([
            'application_parent_signed_at' => $this->candidate->application_parent_signed_at === null ? now() : null,
        ]);

        $candidates->recalculateStatus($this->candidate->refresh(), $this->checklistDefs($this->version));

        Flux::toast('Parent signature status updated.');
    }

    public function confirmVoicePart(int $voicePartId): void
    {
        abort_if($this->writeActionsBlocked(), 403);
        abort_if($this->candidate->voice_part_id !== $voicePartId, 409, 'The voice part has changed. Please reload the page.');
        abort_unless($this->version->availableVoicePartsForGrade($this->candidate->student->grade)->contains('id', $voicePartId), 422);

        $this->candidate->update(['voice_part_confirmed_at' => now()]);
        $this->modal('confirm-voice-part')->close();

        Flux::toast("Voice part confirmed: {$this->candidate->voicePart->name}.", variant: 'success');
    }

    public function editVoicePart(): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        $this->edit_voice_part_id = (string) $this->candidate->voice_part_id;
        $this->resetErrorBag('edit_voice_part_id');
        $this->modal('confirm-voice-part')->close();
        $this->modal('edit-voice-part')->show();
    }

    public function saveVoicePart(CandidateService $candidates, VoicePartChangeService $changes): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        $this->validate([
            'edit_voice_part_id' => ['required', 'integer', Rule::in($this->version->availableVoicePartsForGrade($this->candidate->student->grade)->pluck('id')->all())],
        ]);

        // A student can't vouch for recordings of another part: pending ones
        // must be replaced, and an approved one sends them to their teacher.
        if ((int) $this->edit_voice_part_id === $this->candidate->voice_part_id) {
            $this->candidate->update(['voice_part_confirmed_at' => now()]);
        } else {
            try {
                $changes->change($this->candidate, VoicePart::findOrFail((int) $this->edit_voice_part_id), VoicePartRecordingDecision::Replace);
            } catch (VoicePartChangeRefusedException $e) {
                $this->addError('edit_voice_part_id', $e->getMessage());

                return;
            }
        }

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

    /**
     * Marks the first-visit spotlight tour as seen for this user — a single
     * flag covers every Candidate's Show page, mirroring
     * Events\Show::dismissOrientation()/VersionDashboard::dismissOrientation().
     * Called from the client-side tour engine
     * (resources/views/livewire/sfdi/events/show.blade.php) via a hidden
     * wire:click trigger when the tour finishes or is skipped; the
     * always-visible "Take a tour" button ignores this flag entirely.
     */
    public function dismissOrientation(): void
    {
        Auth::user()->update(['dismissed_sfdi_candidate_orientation_at' => now()]);
    }

    public function withdraw(CandidateService $candidates): void
    {
        abort_if($this->writeActionsBlocked(), 403);

        $candidates->withdrawByCandidate($this->candidate);

        Flux::toast('You have withdrawn from this Event.');

        $this->redirect(route('sfdi.events.index'), navigate: true);
    }

    /**
     * Student-initiated Pay Now (studentfolder-module.md §5.7) — deliberately
     * not behind writeActionsBlocked() (status lock / obligations gate):
     * §5.7 defines its own three-part gate below, independent of §5.3's
     * gates, mirroring CandidateDetail::payNow() (teacher-initiated), which
     * likewise isn't wrapped in that guard.
     */
    public function payNow(PaymentGatewayFactory $factory, CheckoutInProgressGuard $guard, string $feeType): void
    {
        abort_if($this->isEventManagerPreviewSession(), 403);

        $feeType = FeeType::from($feeType);

        abort_unless($this->version->epaymentStudentEnabled(), 403);

        // Scoped to this candidate's own enrolling teacher, not "any teacher
        // at the school" — the source doc's own wording (§5.7 condition 2).
        $optedIn = VersionTeacherEpaymentOptIn::where('version_id', $this->version->id)
            ->where('teacher_id', $this->candidate->teacher_id)
            ->value('opted_in');
        abort_unless($optedIn === true, 403);

        abort_unless(match ($feeType) {
            // Tighter than the teacher-facing rule — cuts off once
            // Adjudication starts, not just once the Version closes (§9
            // item 2, confirmed with the product owner: student-only,
            // registrationFeePayable() itself stays unchanged for teachers).
            FeeType::Registration => $this->version->registrationFeePayableByStudent(),
            FeeType::Participation => $this->version->participationFeePayableOnline(),
            FeeType::Housing => $this->version->housingFeePayable(),
        }, 403);

        $eligibleStates = match ($feeType) {
            FeeType::Registration => CandidateStatus::registrationFeeEligibleStates(),
            FeeType::Participation, FeeType::Housing => [CandidateStatus::Accepted],
        };
        // getRawOriginal(), not the magic-cast property — Larastan can't
        // infer the enum cast through this model's method-based casts()
        // return (cataloged PHPStan-quirks memory).
        $candidateStatus = CandidateStatus::from((string) $this->candidate->getRawOriginal('status'));
        abort_unless(in_array($candidateStatus, $eligibleStates, true), 422);

        // A waived fee is never charged (docs/plans/student-fee-waivers.md) —
        // paymentView() already hides the button; this is the server-side guard.
        abort_if($this->candidate->hasFeeWaived($feeType), 422, 'This fee has been waived by the event.');

        // A stale page or second tab can still post here after the fee is
        // paid; the balance paymentView() hides the button on is the guard.
        if (! $this->paymentView()[$this->feeDueKey($feeType)]) {
            Flux::toast("Nothing is due for the {$feeType->label()}.");

            return;
        }

        // A checkout that's paid but not yet confirmed by the vendor's
        // webhook — see CheckoutInProgressGuard.
        $pending = $guard->forCandidate($this->candidate, $feeType);
        if ($pending !== null) {
            Flux::toast(
                "Your payment is still being confirmed. If you closed the payment page without paying, you can try again after {$guard->retryAfter($pending)->forDisplay()->format('g:i A')}.",
                variant: 'warning',
            );

            return;
        }

        $gateway = $factory->make($this->version);
        $session = $gateway->createCheckoutSession($this->version, collect([$this->candidate]), $this->candidate->student, $feeType);

        $this->redirect($session->redirectUrl, navigate: false);
    }

    /**
     * Called when the page loads and every 10 seconds while a checkout is
     * being confirmed: asks the vendor directly rather than waiting for its
     * webhook (PendingCheckoutConfirmer). Only this candidate's own pending
     * checkouts are checked.
     */
    public function confirmPendingPayments(CheckoutInProgressGuard $guard, PendingCheckoutConfirmer $confirmer): void
    {
        foreach (FeeType::cases() as $feeType) {
            $pending = $guard->forCandidate($this->candidate, $feeType);

            if ($pending !== null && $confirmer->confirm($pending)) {
                Flux::toast("Your {$feeType->label()} payment is confirmed. Thank you!", variant: 'success');
            }
        }
    }

    public function uploadRecording(int $versionUploadFileId): void
    {
        abort_if($this->writeActionsBlocked(), 403);
        abort_if($this->recordingLocked($versionUploadFileId), 403);

        if ($this->candidate->needsVoicePartConfirmation()) {
            $this->modal('confirm-voice-part')->show();

            return;
        }

        $this->uploadVoicePartId = $this->candidate->voice_part_id;
        $this->recording_part_attested = false;
        $this->resetErrorBag('recording_part_attested');
        $this->uploadingVersionUploadFileId = $versionUploadFileId;
        $this->newRecordingFile = null;
        $this->resetErrorBag('newRecordingFile');
        $this->modal('upload-recording')->show();
    }

    public function saveRecording(CandidateService $candidates, RecordingReviewService $review): void
    {
        abort_if($this->candidate->needsVoicePartConfirmation(), 403);
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
            'recording_part_attested' => ['accepted'],
        ], [
            'recording_part_attested.accepted' => 'Please confirm which voice part this recording is.',
        ]);

        if ($this->uploadVoicePartId !== $this->candidate->voice_part_id) {
            $this->addError('newRecordingFile', 'The voice part changed while this was open. Please close this window and upload again.');

            return;
        }

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
                'voice_part_id' => $this->uploadVoicePartId,
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
                'voice_part_id' => $this->uploadVoicePartId,
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
        $checklistDefs = $this->candidateRequirementChecklistDefs();

        $pitchFilesVisible = in_array(
            $this->version->getRawOriginal('pitch_file_visibility'),
            [PitchFileVisibility::Both->value, PitchFileVisibility::Candidate->value],
            true,
        );
        // Skip building the signed S3 URLs at all outside the window —
        // viewPitchFiles() refuses to open the modal then anyway.
        $pitchFilesOpen = $pitchFilesVisible
            && $this->version->candidateReferenceWindowIsOpen()
            && ! $this->confirmationRequired();

        return view('livewire.sfdi.events.show', [
            'checklistDefs' => $checklistDefs,
            'writeActionsBlocked' => $this->writeActionsBlocked(),
            'obligationsBlocked' => $this->isObligationsBlocked(),
            'windowNotYetOpen' => ! $this->version->candidateWindowHasOpened(),
            'windowClosed' => $this->version->candidateWindowHasClosed(),
            'previewSession' => $this->isEventManagerPreviewSession(),
            'candidateRequirementsMet' => $this->candidateRequirementsMet($checklistDefs),
            'uploadSlots' => $this->version->getRawOriginal('audition_type') === AuditionType::Remote->value
                ? $this->version->uploadFiles
                : collect(),
            'candidateUploads' => $this->candidate->uploadFiles->keyBy('version_upload_file_id'),
            'confirmationRequired' => $this->confirmationRequired(),
            'voicePartOffered' => $this->version->availableVoicePartsForGrade($this->candidate->student->grade)->contains('id', $this->candidate->voice_part_id),
            'pitchFilesVisible' => $pitchFilesVisible,
            'pitchFiles' => $pitchFilesOpen ? $this->matchingPitchFiles() : new Collection,
            'registrationPeriod' => $this->version->candidateDate(),
            ...$this->applicationDocumentView(),
            ...$this->paymentView(),
        ]);
    }

    /**
     * Combines both §5.3 gates — used by every write action's guard and by
     * the view to decide whether to render actions or the informational
     * block in their place.
     */
    private function writeActionsBlocked(): bool
    {
        return ! $this->version->candidateWindowIsOpen()
            || $this->isLocked()
            || $this->isObligationsBlocked()
            || $this->isEventManagerPreviewSession();
    }

    /**
     * Asked only while the student can act on it; a read-only page shows
     * the Pitch Files regardless.
     */
    private function confirmationRequired(): bool
    {
        return ! $this->writeActionsBlocked() && $this->candidate->needsVoicePartConfirmation();
    }

    /**
     * True when this session is an Event Manager previewing a real
     * student's pages (WebRegistration::previewAsStudent()) rather than the
     * student's own session — blocks every consequential write action
     * (signing, withdrawing, uploading, paying) while still allowing the
     * completeness fields (home address, emergency contact, etc.) to be
     * filled in via the separate Student Details/Emergency Contacts pages,
     * which aren't gated by this method.
     */
    private function isEventManagerPreviewSession(): bool
    {
        return session('impersonation_scope') === 'event_manager_student_preview';
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
     * @return list<array{label: string, check: \Closure(Candidate): bool, partial?: \Closure(Candidate): bool}>
     */
    private function candidateRequirementChecklistDefs(): array
    {
        return array_values(array_filter(
            $this->checklistDefs($this->version),
            fn (array $def): bool => in_array($def['label'], self::CANDIDATE_REQUIREMENT_LABELS, true),
        ));
    }

    /**
     * True once every currently-required Candidate Requirement is met (or
     * trivially true if this Version requires none) — gates the Application
     * section per the product owner's direction, 2026-08-19: disabled by
     * default, enabled only once these are done. Accepts an already-computed
     * list to avoid recomputing it a second time in render(); action guards
     * (viewApplication(), the two signature toggles) call it with no
     * argument.
     *
     * @param  list<array{label: string, check: \Closure(Candidate): bool, partial?: \Closure(Candidate): bool}>|null  $checklistDefs
     */
    private function candidateRequirementsMet(?array $checklistDefs = null): bool
    {
        $checklistDefs ??= $this->candidateRequirementChecklistDefs();

        foreach ($checklistDefs as $def) {
            if (! ($def['check'])($this->candidate)) {
                return false;
            }
        }

        return true;
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
        return $this->version->pitchFilesForVoicePart($this->candidate->voice_part_id);
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

    /**
     * The Participation Fee summary, for an accepted student once results
     * are released. Null otherwise.
     *
     * @return array{ensemble: string|null, waived: bool, dueCents: int, paidCents: int, optedIn: bool, opensAt: CarbonInterface|null, closesAt: CarbonInterface|null, windowClosed: bool}|null
     */
    private function participationView(CandidateFeeLedger $ledger, bool $optedIn): ?array
    {
        if (! $this->version->collectsParticipationFees()
            || $this->candidate->getRawOriginal('status') !== CandidateStatus::Accepted->value) {
            return null;
        }

        $date = $this->version->participationFeeDate();

        return [
            'ensemble' => $this->candidate->acceptedEnsemble?->name,
            'waived' => $this->candidate->hasFeeWaived(FeeType::Participation),
            'dueCents' => $ledger->dueCents($this->version, $this->candidate, FeeType::Participation),
            'paidCents' => $ledger->paidCents($this->candidate, FeeType::Participation),
            'optedIn' => $optedIn,
            'opensAt' => $this->version->participationFeeWindowIsOpen() ? null : $date?->start_at,
            'closesAt' => $date?->end_at,
            'windowClosed' => $this->version->participationFeeWindowHasClosed(),
        ];
    }

    /**
     * The paymentView() flag that says whether this fee is due.
     *
     * @return 'registrationFeeDue'|'participationFeeDue'|'housingFeeDue'
     */
    private function feeDueKey(FeeType $feeType): string
    {
        return match ($feeType) {
            FeeType::Registration => 'registrationFeeDue',
            FeeType::Participation => 'participationFeeDue',
            FeeType::Housing => 'housingFeeDue',
        };
    }

    /**
     * §5.7's three-part gate (epaymentStudentEnabled, this candidate's own
     * teacher opted in, the relevant fee's timing) plus the same due/paid
     * balance math CandidateDetail::render() already does per fee type —
     * mirrored rather than shared, since the two components have no common
     * base to hang a helper off of and the two call sites would otherwise
     * drift no differently than any other Livewire-component duplication in
     * this codebase (e.g. isLocked()'s own status-cast comment).
     *
     * @return array{registrationFeeDue: bool, participationFeeDue: bool, housingFeeDue: bool, confirmingFeeTypes: list<string>, overpaymentCents: int, waivedFeeLabels: list<string>, candidatePayments: Collection<int, PaymentTransaction>, participation: array{ensemble: string|null, waived: bool, dueCents: int, paidCents: int, optedIn: bool, opensAt: CarbonInterface|null, closesAt: CarbonInterface|null, windowClosed: bool}|null}
     */
    private function paymentView(): array
    {
        $epaymentOptedIn = $this->version->epaymentStudentEnabled() && (bool) VersionTeacherEpaymentOptIn::where('version_id', $this->version->id)
            ->where('teacher_id', $this->candidate->teacher_id)
            ->value('opted_in');

        $candidateStatus = CandidateStatus::from((string) $this->candidate->getRawOriginal('status'));

        // Net of per-student fee waivers — see CandidateFeeCalculator.
        $feeCalculator = app(CandidateFeeCalculator::class);
        $ledger = app(CandidateFeeLedger::class);
        $owed = fn (FeeType $type): bool => $epaymentOptedIn
            && in_array($candidateStatus, $this->version->feePayableStates($type), true)
            && $ledger->balanceCents($this->version, $this->candidate, $type) > 0;

        $registrationFeeDue = $this->version->registrationFeePayableByStudent() && $owed(FeeType::Registration);
        $participationFeeDue = $this->version->participationFeePayableOnline() && $owed(FeeType::Participation);
        $housingFeeDue = $this->version->housingFeePayable() && $owed(FeeType::Housing);
        $overpaymentCents = $ledger->overpaymentCents($this->version, $this->candidate);

        // A due fee whose checkout is paid but not yet confirmed shows
        // "being confirmed" instead of its Pay button (CheckoutInProgressGuard).
        $guard = app(CheckoutInProgressGuard::class);
        $confirmingFeeTypes = [];
        foreach ([FeeType::Registration->value => $registrationFeeDue, FeeType::Participation->value => $participationFeeDue, FeeType::Housing->value => $housingFeeDue] as $type => $due) {
            if ($due && $guard->forCandidate($this->candidate, FeeType::from($type)) !== null) {
                $confirmingFeeTypes[] = $type;
            }
        }

        return [
            'registrationFeeDue' => $registrationFeeDue,
            'participationFeeDue' => $participationFeeDue,
            'housingFeeDue' => $housingFeeDue,
            'confirmingFeeTypes' => $confirmingFeeTypes,
            'overpaymentCents' => $overpaymentCents,
            'participation' => $this->participationView($ledger, $epaymentOptedIn),
            // Waived fees the Version actually charges — shown in place of a
            // Pay button. The waiver reason is EM/RM-only, never shown here.
            'waivedFeeLabels' => array_values(array_map(
                fn (FeeType $type): string => $type->label(),
                array_filter(
                    FeeType::cases(),
                    fn (FeeType $type): bool => $this->candidate->hasFeeWaived($type)
                        && $feeCalculator->configuredFeeCents($this->version, $type) > 0,
                ),
            )),
            'candidatePayments' => PaymentTransaction::whereHas(
                'allocations',
                fn ($q) => $q->where('candidate_id', $this->candidate->id),
            )->with(['allocations' => fn ($q) => $q->where('candidate_id', $this->candidate->id)])
                ->orderByDesc('paid_at')
                ->get(),
        ];
    }
}
