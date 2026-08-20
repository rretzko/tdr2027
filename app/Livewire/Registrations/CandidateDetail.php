<?php

declare(strict_types=1);

namespace App\Livewire\Registrations;

use App\Concerns\GuardsAcceptedObligations;
use App\Concerns\HasCandidateChecklist;
use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\CandidateUploadStatus;
use App\Enums\EmergencyContactRelationship;
use App\Enums\FeeType;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\UploadType;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\EmergencyContact;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Recording;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionInvitation;
use App\Models\VersionUploadFile;
use App\Services\CandidateService;
use App\Services\CoTeacherAccessService;
use App\Services\Payments\PaymentGatewayFactory;
use App\Services\RecordingReviewService;
use App\Support\CandidateApplicationData;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class CandidateDetail extends Component
{
    use GuardsAcceptedObligations;
    use HasCandidateChecklist;
    use WithFileUploads;

    public Version $version;

    public Candidate $candidate;

    // Program name
    public string $program_name = '';

    // Student form
    public string $edit_first_name = '';

    public string $edit_last_name = '';

    public string $edit_voice_part_id = '';

    public string $edit_birthday = '';

    public string $edit_height = '';

    // Home address form
    public string $edit_home_address1 = '';

    public string $edit_home_address2 = '';

    public string $edit_home_city = '';

    public string $edit_home_geo_state = '';

    public string $edit_home_zip_code = '';

    // Emergency contact form
    public ?int $editingEmergencyContactId = null;

    public string $ec_name = '';

    public string $ec_relationship = '';

    public string $ec_cell_phone = '';

    public string $ec_home_phone = '';

    public string $ec_email = '';

    // Recording upload form
    public ?int $uploadingVersionUploadFileId = null;

    public $newRecordingFile = null;

    // Payment form
    public string $payment_type = '';

    public string $payment_amount = '';

    public string $payment_reference_number = '';

    public string $payment_comments = '';

    public string $payment_paid_at = '';

    public function mount(Version $version, Candidate $candidate, CoTeacherAccessService $coTeacherAccess): void
    {
        abort_if($candidate->version_id !== $version->id, 404);
        abort_unless($coTeacherAccess->canAccessCandidate($this->teacher(), $candidate), 403);

        // Obligations standing belongs to the candidate's own recorded
        // teacher (candidate->teacher_id), not the viewer — a co-teacher
        // granted access here may hold no VersionInvitation of their own for
        // this Version at all (docs/plans/co-teacher-definition.md §3).
        $invitation = VersionInvitation::where('version_id', $version->id)
            ->where('teacher_id', $candidate->teacher_id)
            ->first();

        if ($this->redirectUnlessObligationsAccepted($version, $invitation)) {
            return;
        }

        $this->version = $version;
        $this->candidate = $candidate->load([
            'student.user',
            'student.homeAddress',
            'student.emergencyContacts',
            'voicePart',
        ]);

        $this->program_name = $candidate->program_name;
    }

    public function editProgramName(): void
    {
        $this->program_name = $this->candidate->program_name;
        $this->resetErrorBag('program_name');
        $this->modal('edit-program-name')->show();
    }

    public function saveProgramName(CandidateService $candidates): void
    {
        $this->validate(['program_name' => ['required', 'string', 'max:255']]);

        $this->candidate->update(['program_name' => $this->program_name]);
        $candidates->recalculateStatus(
            $this->candidate->refresh(),
            $this->checklistDefs($this->version),
        );

        $this->modal('edit-program-name')->close();

        Flux::toast('Program name saved.');
    }

    public function editStudent(): void
    {
        $student = $this->candidate->student;
        $this->edit_first_name = $student->user->first_name;
        $this->edit_last_name = $student->user->last_name;
        // Sourced from the Candidate, not the Student — this is this
        // registration's voice part, which can differ from whatever the
        // Student's general profile has on file.
        $this->edit_voice_part_id = (string) $this->candidate->voice_part_id;
        $this->edit_birthday = (string) $student->getRawOriginal('birthday');
        $this->edit_height = $student->height !== null ? (string) $student->height : '';
        $this->resetErrorBag(['edit_first_name', 'edit_last_name', 'edit_voice_part_id', 'edit_birthday', 'edit_height']);
        $this->modal('edit-student')->show();
    }

    public function saveStudent(CandidateService $candidates): void
    {
        $this->validate([
            'edit_first_name' => ['required', 'string', 'max:255', "regex:/^[\pL\s'-]+$/u"],
            'edit_last_name' => ['required', 'string', 'max:255', "regex:/^[\pL\s'-]+$/u"],
            'edit_voice_part_id' => ['required', 'integer', Rule::in($this->version->availableVoiceParts()->pluck('id')->all())],
            'edit_birthday' => [
                'nullable', 'date',
                'before_or_equal:'.now()->subYears(9)->format('Y-m-d'),
                'after:'.now()->subYears(20)->format('Y-m-d'),
            ],
            'edit_height' => ['nullable', 'integer', 'min:30', 'max:84'],
        ]);

        $this->candidate->student->user->update([
            'first_name' => $this->edit_first_name,
            'last_name' => $this->edit_last_name,
        ]);

        $this->candidate->student->update([
            'birthday' => $this->edit_birthday !== '' ? $this->edit_birthday : null,
            'height' => $this->edit_height !== '' ? (int) $this->edit_height : null,
        ]);

        $this->candidate->update(['voice_part_id' => (int) $this->edit_voice_part_id]);

        $candidates->recalculateStatus(
            $this->candidate->refresh(),
            $this->checklistDefs($this->version),
        );

        $this->modal('edit-student')->close();

        Flux::toast('Student info saved.');
    }

    public function editHomeAddress(): void
    {
        $homeAddress = $this->candidate->student->homeAddress;
        $this->edit_home_address1 = $homeAddress !== null ? $homeAddress->address1 : '';
        $this->edit_home_address2 = $homeAddress !== null ? ($homeAddress->address2 ?? '') : '';
        $this->edit_home_city = $homeAddress !== null ? $homeAddress->city : '';
        $this->edit_home_geo_state = $homeAddress !== null ? $homeAddress->geo_state : '';
        $this->edit_home_zip_code = $homeAddress !== null ? $homeAddress->zip_code : '';
        $this->resetErrorBag(['edit_home_address1', 'edit_home_address2', 'edit_home_city', 'edit_home_geo_state', 'edit_home_zip_code']);
        $this->modal('edit-home-address')->show();
    }

    public function saveHomeAddress(CandidateService $candidates): void
    {
        $this->validate([
            'edit_home_address1' => ['required', 'string', 'max:255'],
            'edit_home_address2' => ['nullable', 'string', 'max:255'],
            'edit_home_city' => ['required', 'string', 'max:255'],
            'edit_home_geo_state' => ['required', 'string', 'max:2'],
            'edit_home_zip_code' => ['required', 'string', 'max:10'],
        ]);

        $this->candidate->student->homeAddress()->updateOrCreate([], [
            'address1' => $this->edit_home_address1,
            'address2' => $this->edit_home_address2 !== '' ? $this->edit_home_address2 : null,
            'city' => $this->edit_home_city,
            'geo_state' => $this->edit_home_geo_state,
            'zip_code' => $this->edit_home_zip_code,
        ]);

        $candidates->recalculateStatus(
            $this->candidate->load('student.homeAddress')->refresh(),
            $this->checklistDefs($this->version),
        );

        $this->modal('edit-home-address')->close();

        Flux::toast('Home address saved.');
    }

    public function addEmergencyContact(): void
    {
        $this->editingEmergencyContactId = null;
        $this->ec_name = '';
        $this->ec_relationship = '';
        $this->ec_cell_phone = '';
        $this->ec_home_phone = '';
        $this->ec_email = '';
        $this->resetErrorBag(['ec_name', 'ec_relationship', 'ec_cell_phone', 'ec_home_phone', 'ec_email']);
        $this->modal('add-emergency-contact')->show();
    }

    public function editEmergencyContact(int $emergencyContactId): void
    {
        $ec = EmergencyContact::where('id', $emergencyContactId)
            ->where('student_id', $this->candidate->student_id)
            ->first();

        abort_if($ec === null, 404);

        $this->editingEmergencyContactId = $ec->id;
        $this->ec_name = $ec->name;
        $this->ec_relationship = (string) $ec->getRawOriginal('relationship');
        $this->ec_cell_phone = $ec->cell_phone ?? '';
        $this->ec_home_phone = $ec->home_phone ?? '';
        $this->ec_email = $ec->email ?? '';
        $this->resetErrorBag(['ec_name', 'ec_relationship', 'ec_cell_phone', 'ec_home_phone', 'ec_email']);
        $this->modal('add-emergency-contact')->show();
    }

    public function saveEmergencyContact(CandidateService $candidates): void
    {
        $this->validate([
            'ec_name' => ['required', 'string', 'max:255'],
            'ec_relationship' => ['required', 'string', 'in:'.implode(',', array_column(EmergencyContactRelationship::cases(), 'value'))],
            // Cell/email are only mandatory when this Version's config
            // requires them (versions.emergency_contact_cell/email) — the
            // rest of the app (HasCandidateChecklist) is conditional on the
            // same two flags, and emergency_contacts.cell_phone/email are
            // nullable columns precisely because most Versions don't require
            // both.
            'ec_cell_phone' => [(bool) $this->version->emergency_contact_cell ? 'required' : 'nullable', 'string', 'max:30'],
            'ec_home_phone' => ['nullable', 'string', 'max:30'],
            'ec_email' => [(bool) $this->version->emergency_contact_email ? 'required' : 'nullable', 'email', 'max:255'],
        ]);

        $attributes = [
            'name' => $this->ec_name,
            'relationship' => $this->ec_relationship,
            'cell_phone' => $this->ec_cell_phone ?: null,
            'home_phone' => $this->ec_home_phone ?: null,
            'email' => $this->ec_email ?: null,
        ];

        if ($this->editingEmergencyContactId !== null) {
            $ec = EmergencyContact::where('id', $this->editingEmergencyContactId)
                ->where('student_id', $this->candidate->student_id)
                ->first();

            abort_if($ec === null, 404);

            $ec->update($attributes);
        } else {
            $ec = EmergencyContact::create(['student_id' => $this->candidate->student_id, ...$attributes]);

            if ($this->candidate->emergency_contact_id === null) {
                $this->candidate->update(['emergency_contact_id' => $ec->id]);
            }
        }

        $this->editingEmergencyContactId = null;
        $this->ec_name = '';
        $this->ec_relationship = '';
        $this->ec_cell_phone = '';
        $this->ec_home_phone = '';
        $this->ec_email = '';
        $this->resetValidation(['ec_name', 'ec_relationship', 'ec_cell_phone', 'ec_home_phone', 'ec_email']);

        $candidates->recalculateStatus(
            $this->candidate->load('student.emergencyContacts')->refresh(),
            $this->checklistDefs($this->version),
        );

        $this->modal('add-emergency-contact')->close();

        Flux::toast("{$ec->name} saved as emergency contact.");
    }

    public function viewApplication(): void
    {
        $this->modal('view-application')->show();
    }

    public function toggleApplicationCertified(CandidateService $candidates): void
    {
        if ($this->version->getRawOriginal('application_type') !== ApplicationType::Pdf->value) {
            return;
        }

        if ($this->candidate->application_certified_at === null) {
            $this->candidate->update([
                'application_certified_at' => now(),
                'application_certified_by_user_id' => Auth::id(),
            ]);
        } else {
            $this->candidate->update([
                'application_certified_at' => null,
                'application_certified_by_user_id' => null,
            ]);
        }

        $candidates->recalculateStatus($this->candidate->refresh(), $this->checklistDefs($this->version));

        Flux::toast('Application certification updated.');
    }

    public function toggleApplicationCandidateSigned(CandidateService $candidates): void
    {
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
        if ($this->version->getRawOriginal('application_type') !== ApplicationType::EApplication->value) {
            return;
        }

        $this->candidate->update([
            'application_parent_signed_at' => $this->candidate->application_parent_signed_at === null ? now() : null,
        ]);

        $candidates->recalculateStatus($this->candidate->refresh(), $this->checklistDefs($this->version));

        Flux::toast('Parent signature status updated.');
    }

    public function uploadRecording(int $versionUploadFileId): void
    {
        $this->uploadingVersionUploadFileId = $versionUploadFileId;
        $this->newRecordingFile = null;
        $this->resetErrorBag('newRecordingFile');
        $this->modal('upload-recording')->show();
    }

    public function saveRecording(CandidateService $candidates, RecordingReviewService $review): void
    {
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

        // Read/analyze the file before ->store() moves it, and independent
        // of anything actually landing in S3 — a rudimentary, non-blocking
        // assist (filename-vs-slot mismatch + a per-slot duration outlier
        // check), never an automatic reject.
        $reviewResult = $review->evaluate($this->newRecordingFile, $slot, $this->version->uploadFiles);

        $existing = CandidateUploadFile::where('candidate_id', $this->candidate->id)
            ->where('version_upload_file_id', $this->uploadingVersionUploadFileId)
            ->first();

        $path = $this->newRecordingFile->store("candidateUploads/{$this->candidate->id}", 's3');

        if ($existing !== null) {
            // A previously-approved file being replaced has a synced
            // `recordings` row pointing at the now-superseded audio — it
            // must go too, or a judge could score against stale content.
            if ($existing->getRawOriginal('status') === CandidateUploadStatus::Approved->value) {
                $this->removeSyncedRecording($existing);
            }

            $previousUrl = $existing->url;
            $existing->update([
                'url' => $path,
                'uploaded_by_user_id' => Auth::id(),
                ...$reviewResult,
                // A re-upload (including replacing an already-approved file)
                // reverts to pending — the prior decision no longer applies
                // to different content.
                'status' => CandidateUploadStatus::Pending->value,
                'uploaded_at' => now(),
                'decided_at' => null,
                'decided_by_user_id' => null,
            ]);
            Storage::disk('s3')->delete($previousUrl);
        } else {
            CandidateUploadFile::create([
                'candidate_id' => $this->candidate->id,
                'version_upload_file_id' => $this->uploadingVersionUploadFileId,
                'uploaded_by_user_id' => Auth::id(),
                'url' => $path,
                ...$reviewResult,
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

        $this->modal('upload-recording')->close();

        Flux::toast(
            $reviewResult['flagged_at'] !== null
                ? 'Recording uploaded — flagged for your review, see below.'
                : 'Recording uploaded.',
            variant: $reviewResult['flagged_at'] !== null ? 'warning' : 'success',
        );
    }

    public function approveRecording(int $candidateUploadFileId, CandidateService $candidates): void
    {
        $upload = CandidateUploadFile::where('id', $candidateUploadFileId)
            ->where('candidate_id', $this->candidate->id)
            ->first();

        abort_if($upload === null, 404);

        $decidedAt = now();
        $upload->update([
            'status' => CandidateUploadStatus::Approved->value,
            'decided_at' => $decidedAt,
            'decided_by_user_id' => Auth::id(),
        ]);

        // Sync into `recordings` — the only table the Adjudication/judging
        // workflow ever reads from — so an approved upload is actually
        // visible to a judge scoring this Candidate. Only approved uploads
        // are synced; file_type is matched against score_categories.description
        // case-insensitively by AdjudicationService::recordingsForCandidate().
        Recording::updateOrCreate(
            [
                'version_id' => $this->candidate->version_id,
                'candidate_id' => $this->candidate->id,
                'file_type' => $upload->versionUploadFile->name,
            ],
            [
                'uploaded_by' => $upload->uploaded_by_user_id,
                'approved_at' => $decidedAt,
                'approved_by' => Auth::id(),
                'url' => $upload->url,
            ],
        );

        $candidates->recalculateStatus(
            $this->candidate->load('uploadFiles')->refresh(),
            $this->checklistDefs($this->version),
        );

        Flux::toast('Recording approved.');
    }

    public function rejectRecording(int $candidateUploadFileId, CandidateService $candidates): void
    {
        $upload = CandidateUploadFile::where('id', $candidateUploadFileId)
            ->where('candidate_id', $this->candidate->id)
            ->first();

        abort_if($upload === null, 404);

        // Covers both "reject a pending upload" and "remove a previously
        // approved one" — either way, any synced `recordings` row for this
        // slot must go too.
        $this->removeSyncedRecording($upload);

        // Rejecting deletes the file outright and re-opens the slot for
        // another upload — it is never a stored third status.
        Storage::disk('s3')->delete($upload->url);
        $upload->delete();

        $candidates->recalculateStatus(
            $this->candidate->load('uploadFiles')->refresh(),
            $this->checklistDefs($this->version),
        );

        Flux::toast('Recording rejected and removed.');
    }

    private function removeSyncedRecording(CandidateUploadFile $upload): void
    {
        Recording::where('version_id', $this->candidate->version_id)
            ->where('candidate_id', $this->candidate->id)
            ->where('file_type', $upload->versionUploadFile->name)
            ->delete();
    }

    private function allowedRecordingExtensions(): string
    {
        return match ($this->version->getRawOriginal('upload_type')) {
            UploadType::Video->value => 'mp4,mov',
            default => 'mp3,wav,m4a',
        };
    }

    /**
     * Redirects to the vendor's hosted checkout for this one Candidate — see
     * epayment-integration.md §2.1/§3. Gated on epayment_teacher and a real
     * vendor being configured on this Version's Event, plus the requested
     * FeeType's own timing/eligibility gate — registration and participation
     * are never combined into one checkout amount.
     */
    public function payNow(PaymentGatewayFactory $factory, string $feeType): void
    {
        $feeType = FeeType::from($feeType);

        abort_unless($this->version->epaymentTeacherReady(), 403);
        abort_unless(match ($feeType) {
            FeeType::Registration => $this->version->registrationFeePayable(),
            FeeType::Participation => $this->version->participationFeePayable(),
            FeeType::Housing => $this->version->housingFeePayable(),
        }, 403);

        $eligibleStates = match ($feeType) {
            FeeType::Registration => CandidateStatus::registrationFeeEligibleStates(),
            FeeType::Participation, FeeType::Housing => [CandidateStatus::Accepted],
        };
        // getRawOriginal(), not the magic-cast property — Larastan can't
        // infer the enum cast through this method-based casts() return here
        // (cataloged Larastan quirk; see PHPStan-quirks memory).
        $candidateStatus = CandidateStatus::from((string) $this->candidate->getRawOriginal('status'));
        abort_unless(in_array($candidateStatus, $eligibleStates, true), 422);

        $gateway = $factory->make($this->version);
        $session = $gateway->createCheckoutSession($this->version, collect([$this->candidate]), $this->teacher(), $feeType);

        $this->redirect($session->redirectUrl, navigate: false);
    }

    public function recordPayment(): void
    {
        $this->payment_type = '';
        $this->payment_amount = '';
        $this->payment_reference_number = '';
        $this->payment_comments = '';
        $this->payment_paid_at = now()->format('Y-m-d');
        $this->resetErrorBag(['payment_type', 'payment_amount', 'payment_reference_number', 'payment_comments', 'payment_paid_at']);
        $this->modal('record-payment')->show();
    }

    /**
     * Manual entry only (check/PO/cash/other) — a real electronic payment
     * goes through payNow() instead. Writes payment_transactions
     * (source=manual) plus an auto-created 100%-allocated payment_allocations
     * row for this one Candidate, per epayment-integration.md §1.1. Not
     * gated on e-payment being configured at all — recording a manually
     * collected payment is independent of whether electronic payment exists
     * for this Version.
     */
    public function savePayment(): void
    {
        $modalPaymentTypes = [PaymentType::Check->value, PaymentType::PurchaseOrder->value, PaymentType::Cash->value, PaymentType::Other->value, PaymentType::Refund->value];

        $validated = $this->validate([
            'payment_type' => ['required', 'string', Rule::in($modalPaymentTypes)],
            'payment_amount' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'payment_reference_number' => ['nullable', 'string', 'max:255'],
            'payment_comments' => ['nullable', 'string', 'max:1000'],
            'payment_paid_at' => ['required', 'date', 'before_or_equal:today'],
        ]);

        // The form always collects a positive amount — a refund is stored as
        // a negative amount so it nets against the candidate's paid total
        // (see the balance-due math in render()) rather than needing its own
        // sign-aware bookkeeping everywhere else.
        $amountCents = (int) round(((float) $this->payment_amount) * 100);
        if ($validated['payment_type'] === PaymentType::Refund->value) {
            $amountCents = -$amountCents;
        }

        $transaction = PaymentTransaction::create([
            'version_id' => $this->version->id,
            'source' => PaymentSource::Manual,
            'payer_teacher_id' => $this->teacher()->id,
            'school_id' => $this->candidate->school_id,
            'amount' => $amountCents,
            'status' => PaymentTransactionStatus::Completed,
            'payment_type' => $validated['payment_type'],
            'reference_number' => $this->payment_reference_number !== '' ? $this->payment_reference_number : null,
            'comments' => $this->payment_comments !== '' ? $this->payment_comments : null,
            'recorded_by_user_id' => Auth::id(),
            'paid_at' => $this->payment_paid_at,
        ]);

        PaymentAllocation::create([
            'payment_transaction_id' => $transaction->id,
            'candidate_id' => $this->candidate->id,
            'amount' => $amountCents,
            'allocated_by_user_id' => Auth::id(),
            'allocated_at' => now(),
        ]);

        $this->modal('record-payment')->close();

        Flux::toast('Payment recorded.', variant: 'success');
    }

    public function refreshStatus(CandidateService $candidates): void
    {
        $candidates->recalculateStatus(
            $this->candidate->load(['student.homeAddress', 'student.emergencyContacts', 'uploadFiles'])->refresh(),
            $this->checklistDefs($this->version),
        );

        Flux::toast('Status refreshed.');
    }

    public function render(): View
    {
        $this->candidate->load([
            'student.user',
            'student.homeAddress',
            'student.emergencyContacts',
            'voicePart',
            'uploadFiles',
        ]);

        $checklistDefs = $this->checklistDefs($this->version);

        $epaymentTeacherReady = $this->version->epaymentTeacherReady();

        // getRawOriginal(), not the magic-cast property — Larastan can't
        // infer the enum cast through this method-based casts() return here
        // (cataloged Larastan quirk; see PHPStan-quirks memory).
        $candidateStatus = CandidateStatus::from((string) $this->candidate->getRawOriginal('status'));

        // Balance due mirrors PaymentReconciliation::baseRows() — due accrues
        // the registration fee once fee-eligible, plus the participation fee
        // once that window opens and the candidate was Accepted; paid is
        // every Completed allocation on this candidate regardless of which
        // fee it satisfied (registration/participation are never both
        // payable at once — see FeeType). A zero or negative balance
        // (fully paid, or an overpayment) suppresses the button.
        $fees = $this->version->fees()->first();
        $dueCents = (int) ($fees->registration ?? 0);
        if ($this->version->participationFeePayable() && $candidateStatus === CandidateStatus::Accepted) {
            $dueCents += (int) ($fees->participation ?? 0);
        }
        $paidCents = (int) PaymentAllocation::where('candidate_id', $this->candidate->id)
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', PaymentTransactionStatus::Completed->value))
            ->sum('amount');
        $balanceDue = $dueCents - $paidCents > 0;
        $overpaymentCents = max(0, $paidCents - $dueCents);

        $registrationFeeDue = $epaymentTeacherReady
            && $this->version->registrationFeePayable()
            && in_array($candidateStatus, CandidateStatus::registrationFeeEligibleStates(), true)
            && $balanceDue;

        $participationFeeDue = $epaymentTeacherReady
            && $this->version->participationFeePayable()
            && $candidateStatus === CandidateStatus::Accepted
            && $balanceDue;

        // Housing deliberately stays OUTSIDE the combined registration+
        // participation balance above (confirmed with the product owner,
        // studentfolder-module.md §0 second pass, 2026-08-18 — the
        // already-confirmed reconciliation formula, epayment-integration.md
        // §5, does not change) — it gets its own independent due/paid pair,
        // matched via payment_transactions.fee_type (written only by the
        // electronic gateways at checkout; a manually recorded payment
        // isn't attributable to a specific fee type, so it can't satisfy
        // this balance — flag if that gap needs closing later).
        $housingCents = (int) ($fees->housing ?? 0);
        $housingPaidCents = (int) PaymentAllocation::where('candidate_id', $this->candidate->id)
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', PaymentTransactionStatus::Completed->value)
                ->where('fee_type', FeeType::Housing->value))
            ->sum('amount');
        $housingBalanceDue = $housingCents - $housingPaidCents > 0;

        $housingFeeDue = $epaymentTeacherReady
            && $this->version->housingFeePayable()
            && $candidateStatus === CandidateStatus::Accepted
            && $housingBalanceDue;

        return view('livewire.registrations.candidate-detail', [
            'checklistDefs' => $checklistDefs,
            'relationships' => EmergencyContactRelationship::cases(),
            'voiceParts' => $this->version->availableVoiceParts(),
            'uploadSlots' => $this->version->getRawOriginal('audition_type') === AuditionType::Remote->value
                ? $this->version->uploadFiles
                : collect(),
            'candidateUploads' => $this->candidate->uploadFiles->keyBy('version_upload_file_id'),
            'registrationFeeDue' => $registrationFeeDue,
            'participationFeeDue' => $participationFeeDue,
            'housingFeeDue' => $housingFeeDue,
            'overpaymentCents' => $overpaymentCents,
            'candidatePayments' => PaymentTransaction::whereHas(
                'allocations',
                fn ($q) => $q->where('candidate_id', $this->candidate->id),
            )->with(['allocations' => fn ($q) => $q->where('candidate_id', $this->candidate->id)])
                ->orderByDesc('paid_at')
                ->orderByDesc('created_at')
                ->get(),
            ...$this->applicationDocumentView(),
        ]);
    }

    /**
     * Same merge-token computation as CandidateApplicationPdfController, so
     * the in-modal preview and the actual PDF download can never drift —
     * both render the identical shared partial (candidate-application.document).
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
                // partial (product-owner direction, 2026-08-18) — reflects
                // the student's own self-attestation regardless of which
                // role (teacher or student) is viewing this document.
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

    private function teacher(): Teacher
    {
        return Auth::user()->teacher;
    }
}
