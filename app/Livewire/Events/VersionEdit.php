<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Enums\VersionApplicationStatus;
use App\Enums\VersionDateType;
use App\Enums\VersionObligationStatus;
use App\Models\County;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionDate;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionFee;
use App\Models\VersionMembershipRequirement;
use App\Models\VersionObligation;
use App\Models\VersionUploadFile;
use App\Services\VersionRoleAssignmentService;
use App\Support\CandidateApplicationData;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('components.layouts.app')]
class VersionEdit extends Component
{
    /**
     * Ordinal color ramp for upload_files.order_by (position in a sequence, not identity):
     * one hue, monotone lightness, validated in both light/dark via the dataviz color formula.
     * order_by values beyond the ramp reuse the darkest step.
     */
    private const UPLOAD_FILE_ORDER_COLORS = ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab'];

    public Version $version;

    public string $activeTab = 'general';

    // General tab
    public string $name = '';

    public string $short_name = '';

    public string $senior_class_of = '';

    public string $status = '';

    public string $audition_type = '';

    public string $audition_timeslot = '0';

    public string $application_type = '';

    public string $upload_type = '';

    public string $judge_count = '1';

    public string $score_order = '';

    public string $pitch_file_visibility = '';

    public string $max_registrants = '';

    public string $max_upper_voice_registrants = '0';

    /** @var array<int, array{name: string, order_by: int}> keyed by version_upload_files.id */
    public array $upload_files = [];

    public string $new_upload_file_name = '';

    public bool $birthday = false;

    public bool $emergency_contact_name = true;

    public bool $emergency_contact_cell = true;

    public bool $emergency_contact_email = false;

    public bool $height = false;

    public bool $home_address = false;

    public bool $release_confidential_results = false;

    public bool $shirt_size = false;

    public bool $teacher_cell = true;

    // Dates tab — keyed by VersionDateType value
    /** @var array<string, string> */
    public array $date_start = [];

    /** @var array<string, string> */
    public array $date_end = [];

    // Fees tab (in dollars, stored as cents)
    public string $fee_registration = '';

    public string $fee_on_site_registration = '';

    public string $fee_participation = '';

    public string $fee_epayment_surcharge = '';

    public string $fee_housing = '';

    // Requirements tab
    public bool $membership_card = false;

    public string $membership_valid_thru = '';

    /** @var list<int> */
    public array $selected_county_ids = [];

    /** @var array<int, int> ensemble_id → order_by */
    public array $ensemble_order = [];

    // Application tab
    public string $student_endorsement_body = '';

    public string $parent_endorsement_body = '';

    public string $teacher_principal_endorsement_body = '';

    public string $schedule_body = '';

    public string $policies_body = '';

    public string $application_status = 'draft';

    public ?string $application_published_at = null;

    // Obligations tab
    public string $obligation_title = '';

    public string $obligation_body = '';

    public string $obligation_status = 'draft';

    public ?string $obligation_published_at = null;

    public int $obligation_response_count = 0;

    // Roles tab
    public string $assign_email = '';

    public string $assign_role = '';

    public function mount(Version $version, VersionRoleAssignmentService $service): void
    {
        abort_unless($service->canAccessVersion(Auth::user(), $version), 403);

        $this->version = $version->load(['dates', 'fees', 'membershipRequirement', 'counties', 'uploadFiles', 'obligation', 'candidateApplication']);

        $this->name = $version->name;
        $this->short_name = $version->short_name ?? '';
        $this->senior_class_of = (string) $version->senior_class_of;
        $this->status = $version->getRawOriginal('status');
        $this->audition_type = $version->getRawOriginal('audition_type');
        $this->audition_timeslot = (string) $version->audition_timeslot;
        $this->application_type = $version->getRawOriginal('application_type');
        $this->upload_type = $version->getRawOriginal('upload_type');
        $this->judge_count = (string) $version->judge_count;
        $this->score_order = $version->getRawOriginal('score_order');
        $this->pitch_file_visibility = $version->getRawOriginal('pitch_file_visibility');
        $this->max_registrants = $version->max_registrants !== null ? (string) $version->max_registrants : '';
        $this->max_upper_voice_registrants = $version->max_upper_voice_registrants !== null ? (string) $version->max_upper_voice_registrants : '';
        $this->birthday = (bool) $version->birthday;
        $this->emergency_contact_name = (bool) $version->emergency_contact_name;
        $this->emergency_contact_cell = (bool) $version->emergency_contact_cell;
        $this->emergency_contact_email = (bool) $version->emergency_contact_email;
        $this->height = (bool) $version->height;
        $this->home_address = (bool) $version->home_address;
        $this->release_confidential_results = (bool) $version->release_confidential_results;
        $this->shirt_size = (bool) $version->shirt_size;
        $this->teacher_cell = (bool) $version->teacher_cell;

        foreach ($version->dates as $vd) {
            $key = $vd->getRawOriginal('date_type');
            $rawStart = $vd->getRawOriginal('start_at');
            $rawEnd = $vd->getRawOriginal('end_at');
            $this->date_start[$key] = $rawStart ? date('Y-m-d\TH:i', (int) strtotime((string) $rawStart)) : '';
            $this->date_end[$key] = $rawEnd ? date('Y-m-d\TH:i', (int) strtotime((string) $rawEnd)) : '';
        }

        $fees = $version->fees;
        $this->fee_registration = $fees ? number_format($fees->registration / 100, 2, '.', '') : '0.00';
        $this->fee_on_site_registration = $fees ? number_format($fees->on_site_registration / 100, 2, '.', '') : '0.00';
        $this->fee_participation = $fees ? number_format($fees->participation / 100, 2, '.', '') : '0.00';
        $this->fee_epayment_surcharge = $fees ? number_format($fees->epayment_surcharge / 100, 2, '.', '') : '0.00';
        $this->fee_housing = $fees ? number_format($fees->housing / 100, 2, '.', '') : '0.00';

        $req = $version->membershipRequirement;
        $this->membership_card = $req !== null && (bool) $req->membership_card;
        $rawValidThru = $req !== null ? $req->getRawOriginal('valid_thru') : null;
        $this->membership_valid_thru = $rawValidThru !== null ? (string) $rawValidThru : '';

        $this->selected_county_ids = $version->counties->pluck('county_id')->map(fn ($id) => (int) $id)->all();

        $existingOrder = $version->ensembleOrder->keyBy('ensemble_id');
        foreach ($version->event->ensembles as $ensemble) {
            $row = $existingOrder->get($ensemble->id);
            $this->ensemble_order[$ensemble->id] = $row !== null ? (int) $row->order_by : 1;
        }

        foreach ($version->uploadFiles as $uploadFile) {
            $this->upload_files[$uploadFile->id] = [
                'name' => $uploadFile->name,
                'order_by' => (int) $uploadFile->order_by,
            ];
        }

        $application = $version->candidateApplication;
        $this->student_endorsement_body = $application !== null ? $application->student_endorsement_body : '';
        $this->parent_endorsement_body = $application !== null ? $application->parent_endorsement_body : '';
        $this->teacher_principal_endorsement_body = $application !== null ? ($application->teacher_principal_endorsement_body ?? '') : '';
        $this->schedule_body = $application !== null ? ($application->schedule_body ?? '') : '';
        $this->policies_body = $application !== null ? ($application->policies_body ?? '') : '';
        $this->application_status = $application !== null ? $application->getRawOriginal('status') : VersionApplicationStatus::Draft->value;
        $rawApplicationPublishedAt = $application !== null ? $application->getRawOriginal('published_at') : null;
        $this->application_published_at = $rawApplicationPublishedAt !== null
            ? Carbon::parse($rawApplicationPublishedAt)->format('M j, Y g:ia')
            : null;

        $obligation = $version->obligation;
        $this->obligation_title = $obligation !== null ? ($obligation->title ?? '') : '';
        $this->obligation_body = $obligation !== null ? $obligation->body : '';
        $this->obligation_status = $obligation !== null ? $obligation->getRawOriginal('status') : VersionObligationStatus::Draft->value;
        $rawPublishedAt = $obligation !== null ? $obligation->getRawOriginal('published_at') : null;
        $this->obligation_published_at = $rawPublishedAt !== null
            ? Carbon::parse($rawPublishedAt)->format('M j, Y g:ia')
            : null;
        $this->obligation_response_count = $obligation !== null ? $obligation->responses()->count() : 0;
    }

    public function saveGeneral(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'senior_class_of' => ['required', 'integer', 'min:2000', 'max:2100'],
            'status' => ['required', 'string', 'in:'.implode(',', array_column(EventStatus::cases(), 'value'))],
            'audition_type' => ['required', 'string', 'in:'.implode(',', array_column(AuditionType::cases(), 'value'))],
            'audition_timeslot' => $this->audition_type === AuditionType::InPerson->value
                ? ['required', 'integer', 'min:5', 'max:120']
                : ['nullable', 'integer', 'min:0', 'max:120'],
            'application_type' => ['required', 'string', 'in:'.implode(',', array_column(ApplicationType::cases(), 'value'))],
            'upload_type' => ['required', 'string', 'in:'.implode(',', array_column(UploadType::cases(), 'value'))],
            'judge_count' => ['required', 'integer', 'min:1', 'max:20'],
            'score_order' => ['required', 'string', 'in:'.implode(',', array_column(ScoreOrder::cases(), 'value'))],
            'pitch_file_visibility' => ['required', 'string', 'in:'.implode(',', array_column(PitchFileVisibility::cases(), 'value'))],
            'max_registrants' => ['nullable', 'integer', 'min:0'],
            'max_upper_voice_registrants' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->version->update([
            'name' => $validated['name'],
            'short_name' => ($validated['short_name'] ?? '') ?: null,
            'senior_class_of' => (int) $validated['senior_class_of'],
            'status' => $validated['status'],
            'audition_type' => $validated['audition_type'],
            'audition_timeslot' => ($validated['audition_timeslot'] ?? '') !== '' ? (int) $validated['audition_timeslot'] : 0,
            'application_type' => $validated['application_type'],
            'upload_type' => $validated['upload_type'],
            'judge_count' => (int) $validated['judge_count'],
            'score_order' => $validated['score_order'],
            'pitch_file_visibility' => $validated['pitch_file_visibility'],
            'max_registrants' => ($validated['max_registrants'] ?? '') !== '' && (int) $validated['max_registrants'] !== 0 ? (int) $validated['max_registrants'] : null,
            'max_upper_voice_registrants' => ($validated['max_upper_voice_registrants'] ?? '') !== '' ? (int) $validated['max_upper_voice_registrants'] : null,
        ]);

        Flux::toast("{$this->version->name} general settings saved.");
    }

    public function saveDates(): void
    {
        $rules = [];

        foreach (VersionDateType::cases() as $type) {
            $key = $type->value;
            $rules["date_start.{$key}"] = ['nullable', 'date'];
            if ($type->hasEndAt()) {
                $rules["date_end.{$key}"] = ['nullable', 'date', "after_or_equal:date_start.{$key}"];
            }
        }

        $this->validate($rules);

        foreach (VersionDateType::cases() as $type) {
            $key = $type->value;
            $startRaw = $this->date_start[$key] ?? '';

            if ($startRaw === '') {
                VersionDate::where('version_id', $this->version->id)
                    ->where('date_type', $key)
                    ->delete();

                continue;
            }

            $endRaw = $type->hasEndAt() ? ($this->date_end[$key] ?? null) : null;

            VersionDate::updateOrCreate(
                ['version_id' => $this->version->id, 'date_type' => $key],
                [
                    'start_at' => $startRaw,
                    'end_at' => $endRaw ?: null,
                ],
            );
        }

        Flux::toast('Version dates saved.');
    }

    public function saveFees(): void
    {
        $this->validate([
            'fee_registration' => ['required', 'numeric', 'min:0'],
            'fee_on_site_registration' => ['required', 'numeric', 'min:0'],
            'fee_participation' => ['required', 'numeric', 'min:0'],
            'fee_epayment_surcharge' => ['required', 'numeric', 'min:0'],
            'fee_housing' => ['required', 'numeric', 'min:0'],
        ]);

        VersionFee::updateOrCreate(
            ['version_id' => $this->version->id],
            [
                'registration' => (int) round((float) $this->fee_registration * 100),
                'on_site_registration' => (int) round((float) $this->fee_on_site_registration * 100),
                'participation' => (int) round((float) $this->fee_participation * 100),
                'epayment_surcharge' => (int) round((float) $this->fee_epayment_surcharge * 100),
                'housing' => (int) round((float) $this->fee_housing * 100),
            ],
        );

        Flux::toast('Version fees saved.');
    }

    public function saveRequirements(): void
    {
        $validated = $this->validate([
            'membership_card' => ['boolean'],
            'membership_valid_thru' => ['nullable', 'date'],
            'selected_county_ids' => ['array'],
            'selected_county_ids.*' => ['integer', 'exists:counties,id'],
            'birthday' => ['boolean'],
            'emergency_contact_name' => ['boolean'],
            'emergency_contact_cell' => ['boolean'],
            'emergency_contact_email' => ['boolean'],
            'height' => ['boolean'],
            'home_address' => ['boolean'],
            'release_confidential_results' => ['boolean'],
            'shirt_size' => ['boolean'],
            'teacher_cell' => ['boolean'],
        ]);

        VersionMembershipRequirement::updateOrCreate(
            ['version_id' => $this->version->id],
            [
                'membership_card' => $this->membership_card,
                'valid_thru' => $this->membership_valid_thru ?: null,
            ],
        );

        $this->version->update([
            'birthday' => $validated['birthday'],
            'emergency_contact_name' => $validated['emergency_contact_name'],
            'emergency_contact_cell' => $validated['emergency_contact_cell'],
            'emergency_contact_email' => $validated['emergency_contact_email'],
            'height' => $validated['height'],
            'home_address' => $validated['home_address'],
            'release_confidential_results' => $validated['release_confidential_results'],
            'shirt_size' => $validated['shirt_size'],
            'teacher_cell' => $validated['teacher_cell'],
        ]);

        $this->version->counties()->delete();

        foreach ($this->selected_county_ids as $countyId) {
            $this->version->counties()->create(['county_id' => $countyId]);
        }

        Flux::toast('Version requirements saved.');
    }

    public function saveApplication(): void
    {
        $validated = $this->validateApplication();

        VersionApplication::updateOrCreate(
            ['version_id' => $this->version->id],
            $validated,
        )->refresh();

        Flux::toast('Candidate Application saved.');
    }

    public function publishApplication(): void
    {
        $validated = $this->validateApplication();

        $application = VersionApplication::updateOrCreate(
            ['version_id' => $this->version->id],
            [
                ...$validated,
                'status' => VersionApplicationStatus::Published->value,
                'published_at' => now(),
                'published_by_user_id' => Auth::id(),
            ],
        );

        $this->application_status = $application->getRawOriginal('status');
        $this->application_published_at = Carbon::parse($application->getRawOriginal('published_at'))->format('M j, Y g:ia');

        Flux::toast(text: 'Candidate Application published — visible on candidate records.', variant: 'success');
    }

    public function unpublishApplication(): void
    {
        $application = $this->version->candidateApplication;

        if ($application === null) {
            return;
        }

        $application->update(['status' => VersionApplicationStatus::Draft->value]);

        $this->application_status = VersionApplicationStatus::Draft->value;

        Flux::toast(text: 'Candidate Application unpublished — hidden from candidate records until republished.', variant: 'warning');
    }

    public function downloadApplicationPreviewPdf(): StreamedResponse
    {
        $data = CandidateApplicationData::placeholder($this->version);

        $studentBody = VersionApplication::mergeTokens($this->student_endorsement_body, $data);
        $parentBody = VersionApplication::mergeTokens($this->parent_endorsement_body, $data);
        $teacherBody = $this->teacher_principal_endorsement_body !== ''
            ? VersionApplication::mergeTokens($this->teacher_principal_endorsement_body, $data)
            : null;
        $scheduleBody = $this->schedule_body !== ''
            ? VersionApplication::mergeTokens($this->schedule_body, $data)
            : null;
        $policiesBody = $this->policies_body !== ''
            ? VersionApplication::mergeTokens($this->policies_body, $data)
            : null;

        $pdf = Pdf::loadView('pdf.candidate-application', [
            'version' => $this->version,
            'data' => $data,
            'studentBody' => $studentBody,
            'parentBody' => $parentBody,
            'teacherBody' => $teacherBody,
            'scheduleBody' => $scheduleBody,
            'policiesBody' => $policiesBody,
            'showTeacherSection' => $this->version->getRawOriginal('application_type') === ApplicationType::Pdf->value,
        ]);

        $filename = 'application-preview-'.Str::slug($this->version->short_name ?? $this->version->name).'.pdf';

        return response()->streamDownload(fn () => print $pdf->output(), $filename);
    }

    /**
     * @return array{student_endorsement_body: string, parent_endorsement_body: string, teacher_principal_endorsement_body: ?string, schedule_body: ?string, policies_body: ?string}
     */
    private function validateApplication(): array
    {
        $isPdfMode = $this->version->getRawOriginal('application_type') === ApplicationType::Pdf->value;

        $validated = $this->validate([
            'student_endorsement_body' => ['required', 'string'],
            'parent_endorsement_body' => ['required', 'string'],
            'teacher_principal_endorsement_body' => [$isPdfMode ? 'required' : 'nullable', 'string'],
            'schedule_body' => ['nullable', 'string'],
            'policies_body' => ['nullable', 'string'],
        ]);

        return [
            'student_endorsement_body' => $validated['student_endorsement_body'],
            'parent_endorsement_body' => $validated['parent_endorsement_body'],
            'teacher_principal_endorsement_body' => $isPdfMode ? $validated['teacher_principal_endorsement_body'] : null,
            'schedule_body' => $validated['schedule_body'] !== '' ? $validated['schedule_body'] : null,
            'policies_body' => $validated['policies_body'] !== '' ? $validated['policies_body'] : null,
        ];
    }

    public function saveObligation(): void
    {
        $validated = $this->validate([
            'obligation_title' => ['nullable', 'string', 'max:255'],
            'obligation_body' => ['required', 'string'],
        ]);

        $obligation = VersionObligation::updateOrCreate(
            ['version_id' => $this->version->id],
            [
                'title' => ($validated['obligation_title'] ?? '') ?: null,
                'body' => $validated['obligation_body'],
            ],
        )->refresh();

        $this->obligation_status = $obligation->getRawOriginal('status');
        $this->obligation_response_count = $obligation->responses()->count();

        Flux::toast('Obligations saved.');
    }

    public function publishObligation(): void
    {
        $validated = $this->validate([
            'obligation_title' => ['nullable', 'string', 'max:255'],
            'obligation_body' => ['required', 'string'],
        ]);

        $obligation = VersionObligation::updateOrCreate(
            ['version_id' => $this->version->id],
            [
                'title' => ($validated['obligation_title'] ?? '') ?: null,
                'body' => $validated['obligation_body'],
                'status' => VersionObligationStatus::Published->value,
                'published_at' => now(),
                'published_by_user_id' => Auth::id(),
            ],
        );

        $this->obligation_status = $obligation->getRawOriginal('status');
        $this->obligation_published_at = Carbon::parse($obligation->getRawOriginal('published_at'))->format('M j, Y g:ia');

        Flux::toast(text: 'Obligations published — teachers can now view and respond.', variant: 'success');
    }

    public function unpublishObligation(): void
    {
        $obligation = $this->version->obligation;

        if ($obligation === null) {
            return;
        }

        $obligation->update(['status' => VersionObligationStatus::Draft->value]);

        $this->obligation_status = VersionObligationStatus::Draft->value;

        Flux::toast(text: 'Obligations unpublished — hidden from teachers until republished.', variant: 'warning');
    }

    public function saveEnsembleOrder(): void
    {
        $this->validate([
            'ensemble_order' => ['array'],
            'ensemble_order.*' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        foreach ($this->ensemble_order as $ensembleId => $orderBy) {
            VersionEnsembleOrder::updateOrCreate(
                ['version_id' => $this->version->id, 'ensemble_id' => $ensembleId],
                ['order_by' => (int) $orderBy],
            );
        }

        Flux::toast('Ensemble order saved.');
    }

    public function addUploadFile(): void
    {
        $validated = $this->validate([
            'new_upload_file_name' => ['required', 'string', 'max:100'],
        ]);

        $nextOrder = ((int) $this->version->uploadFiles()->max('order_by')) + 1;

        $uploadFile = $this->version->uploadFiles()->create([
            'name' => $validated['new_upload_file_name'],
            'order_by' => $nextOrder,
        ]);

        $this->upload_files[$uploadFile->id] = ['name' => $uploadFile->name, 'order_by' => $uploadFile->order_by];
        $this->new_upload_file_name = '';
        $this->resetValidation('new_upload_file_name');

        Flux::toast("\"{$uploadFile->name}\" added to expected uploads.");
    }

    public function saveUploadFiles(): void
    {
        $this->validate([
            'upload_files' => ['array'],
            'upload_files.*.name' => ['required', 'string', 'max:100'],
            'upload_files.*.order_by' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        foreach ($this->upload_files as $id => $row) {
            VersionUploadFile::where('id', $id)->where('version_id', $this->version->id)->update([
                'name' => $row['name'],
                'order_by' => (int) $row['order_by'],
            ]);
        }

        Flux::toast('Expected upload files saved.');
    }

    public function removeUploadFile(int $id): void
    {
        VersionUploadFile::where('id', $id)->where('version_id', $this->version->id)->delete();

        unset($this->upload_files[$id]);

        Flux::toast('Upload file removed.');
    }

    public function uploadFileOrderColor(int $orderBy): string
    {
        $index = min(max($orderBy, 1), count(self::UPLOAD_FILE_ORDER_COLORS)) - 1;

        return self::UPLOAD_FILE_ORDER_COLORS[$index];
    }

    public function assignRole(VersionRoleAssignmentService $service): void
    {
        $validated = $this->validate([
            'assign_email' => ['required', 'email'],
            'assign_role' => ['required', 'string', 'in:'.implode(',', $service->assignableRoleNames())],
        ]);

        $targetUser = User::where('email', $validated['assign_email'])->first();

        if ($targetUser === null) {
            $this->addError('assign_email', 'No user found with that email address.');

            return;
        }

        $service->assignRole(Auth::user(), $this->version, $targetUser, $validated['assign_role']);

        $this->assign_email = '';
        $this->assign_role = '';
        $this->resetValidation();

        Flux::toast("{$targetUser->name} assigned as {$validated['assign_role']}.");
    }

    public function selectAssignEmail(int $userId): void
    {
        $this->assign_email = User::findOrFail($userId)->email;
    }

    /**
     * @return Collection<int, User>
     */
    private function assignEmailSuggestions(): Collection
    {
        $term = trim($this->assign_email);

        if (mb_strlen($term) < 4) {
            return collect();
        }

        return User::query()
            ->where('email', '!=', $term)
            ->where(function ($query) use ($term) {
                $query->where('email', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%");
            })
            ->orderBy('name')
            ->limit(5)
            ->get();
    }

    public function revokeRole(VersionRoleAssignmentService $service, int $userId, string $role): void
    {
        $targetUser = User::findOrFail($userId);

        $service->revokeRole(Auth::user(), $this->version, $targetUser, $role);

        Flux::toast("{$targetUser->name} removed as {$role}.");
    }

    public function render(VersionRoleAssignmentService $service): View
    {
        $applicationPreviewData = CandidateApplicationData::placeholder($this->version);

        return view('livewire.events.version-edit', [
            'statuses' => EventStatus::cases(),
            'auditionTypes' => AuditionType::cases(),
            'applicationTypes' => ApplicationType::cases(),
            'uploadTypes' => UploadType::cases(),
            'scoreOrders' => ScoreOrder::cases(),
            'pitchVisibilities' => PitchFileVisibility::cases(),
            'dateTypes' => VersionDateType::cases(),
            'counties' => County::orderBy('name')->get(),
            'eventEnsembles' => $this->version->event->ensembles()->orderBy('name')->get()
                ->sortBy(fn ($ensemble) => $this->ensemble_order[$ensemble->id] ?? PHP_INT_MAX)
                ->values(),
            'roleAssignments' => $service->assignmentsForVersion($this->version),
            'canManageRoles' => $service->canManageVersionRoles(Auth::user(), $this->version),
            'assignableRoles' => $service->assignableRoleNames(),
            'assignEmailSuggestions' => $this->assignEmailSuggestions(),
            'obligationPreviewBody' => VersionObligation::mergeTokens($this->obligation_body, $this->version),
            'applicationPreviewData' => $applicationPreviewData,
            'applicationPreviewStudentBody' => VersionApplication::mergeTokens($this->student_endorsement_body, $applicationPreviewData),
            'applicationPreviewParentBody' => VersionApplication::mergeTokens($this->parent_endorsement_body, $applicationPreviewData),
            'applicationPreviewTeacherBody' => $this->teacher_principal_endorsement_body !== ''
                ? VersionApplication::mergeTokens($this->teacher_principal_endorsement_body, $applicationPreviewData)
                : null,
            'applicationPreviewScheduleBody' => $this->schedule_body !== ''
                ? VersionApplication::mergeTokens($this->schedule_body, $applicationPreviewData)
                : null,
            'applicationPreviewPoliciesBody' => $this->policies_body !== ''
                ? VersionApplication::mergeTokens($this->policies_body, $applicationPreviewData)
                : null,
        ]);
    }
}
