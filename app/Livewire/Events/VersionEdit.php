<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use App\Enums\VersionDateType;
use App\Models\County;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionFee;
use App\Models\VersionMembershipRequirement;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class VersionEdit extends Component
{
    public Version $version;

    public string $activeTab = 'general';

    // General tab
    public string $name = '';

    public string $short_name = '';

    public string $senior_class_of = '';

    public string $status = '';

    public string $audition_type = '';

    public string $audition_timeslot = '20';

    public string $application_type = '';

    public string $upload_type = '';

    public string $judge_count = '1';

    public string $score_order = '';

    public string $pitch_file_visibility = '';

    public string $max_registrants = '';

    public string $max_upper_voice_registrants = '';

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

    // Roles tab
    public string $assign_email = '';

    public string $assign_role = '';

    public function mount(Version $version, VersionRoleAssignmentService $service): void
    {
        abort_unless($service->canAccessVersion(Auth::user(), $version), 403);

        $this->version = $version->load(['dates', 'fees', 'membershipRequirement', 'counties']);

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
    }

    public function saveGeneral(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'senior_class_of' => ['required', 'integer', 'min:2000', 'max:2100'],
            'status' => ['required', 'string', 'in:'.implode(',', array_column(EventStatus::cases(), 'value'))],
            'audition_type' => ['required', 'string', 'in:'.implode(',', array_column(AuditionType::cases(), 'value'))],
            'audition_timeslot' => ['required', 'integer', 'min:5', 'max:120'],
            'application_type' => ['required', 'string', 'in:'.implode(',', array_column(ApplicationType::cases(), 'value'))],
            'upload_type' => ['required', 'string', 'in:'.implode(',', array_column(UploadType::cases(), 'value'))],
            'judge_count' => ['required', 'integer', 'min:1', 'max:20'],
            'score_order' => ['required', 'string', 'in:'.implode(',', array_column(ScoreOrder::cases(), 'value'))],
            'pitch_file_visibility' => ['required', 'string', 'in:'.implode(',', array_column(PitchFileVisibility::cases(), 'value'))],
            'max_registrants' => ['nullable', 'integer', 'min:1'],
            'max_upper_voice_registrants' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->version->update([
            'name' => $validated['name'],
            'short_name' => ($validated['short_name'] ?? '') ?: null,
            'senior_class_of' => (int) $validated['senior_class_of'],
            'status' => $validated['status'],
            'audition_type' => $validated['audition_type'],
            'audition_timeslot' => (int) $validated['audition_timeslot'],
            'application_type' => $validated['application_type'],
            'upload_type' => $validated['upload_type'],
            'judge_count' => (int) $validated['judge_count'],
            'score_order' => $validated['score_order'],
            'pitch_file_visibility' => $validated['pitch_file_visibility'],
            'max_registrants' => ($validated['max_registrants'] ?? '') !== '' ? (int) $validated['max_registrants'] : null,
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
        ]);
    }
}
