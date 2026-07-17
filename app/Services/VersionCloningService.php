<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\VersionApplicationStatus;
use App\Enums\VersionInvitationStatus;
use App\Enums\VersionObligationStatus;
use App\Models\EpaymentCredential;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionApplication;
use App\Models\VersionCounty;
use App\Models\VersionDate;
use App\Models\VersionEnsembleOrder;
use App\Models\VersionFee;
use App\Models\VersionInvitation;
use App\Models\VersionMembershipRequirement;
use App\Models\VersionObligation;
use App\Models\VersionPitchFile;
use App\Models\VersionUploadFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VersionCloningService
{
    /**
     * Clones $source into a brand-new Version plus its configuration
     * (dates, fees, counties, ensemble order, upload/pitch files,
     * membership requirement, obligation, application, epayment
     * credential, and non-rejected invitations). Deliberately excludes
     * version_timeslots, candidates, and version_invitation_requests —
     * roster/season-specific data that a new Version must build fresh.
     *
     * @param  array{name: string, short_name: ?string, senior_class_of: int}  $overrides
     */
    public function cloneFrom(Version $source, array $overrides, User $invitedBy): Version
    {
        return DB::transaction(function () use ($source, $overrides, $invitedBy): Version {
            $version = Version::create([
                ...$source->only([
                    'application_type', 'audition_timeslot', 'audition_type',
                    'birthday', 'emergency_contact_name', 'emergency_contact_cell', 'emergency_contact_email',
                    'height', 'home_address', 'judge_count',
                    'max_registrants', 'max_upper_voice_registrants',
                    'pitch_file_visibility', 'release_confidential_results',
                    'score_order', 'shirt_size', 'teacher_cell', 'upload_type',
                ]),
                'event_id' => $source->event_id,
                'name' => $overrides['name'],
                'short_name' => $overrides['short_name'],
                'senior_class_of' => $overrides['senior_class_of'],
                'status' => EventStatus::Sandbox->value,
            ]);

            $this->cloneDates($source, $version);
            $this->cloneCounties($source, $version);
            $this->cloneEnsembleOrder($source, $version);
            $this->cloneFees($source, $version);
            $this->cloneMembershipRequirement($source, $version);
            $this->cloneObligation($source, $version);
            $this->cloneApplication($source, $version);
            $this->clonePitchFiles($source, $version);
            $this->cloneUploadFiles($source, $version);
            $this->cloneEpaymentCredential($source, $version);
            $this->cloneInvitations($source, $version, $invitedBy);

            return $version;
        });
    }

    private function cloneDates(Version $source, Version $version): void
    {
        foreach ($source->dates as $date) {
            $rawEndAt = $date->getRawOriginal('end_at');

            VersionDate::create([
                'version_id' => $version->id,
                'date_type' => $date->getRawOriginal('date_type'),
                'start_at' => Carbon::parse($date->getRawOriginal('start_at'))->addYear(),
                'end_at' => $rawEndAt !== null ? Carbon::parse($rawEndAt)->addYear() : null,
            ]);
        }
    }

    private function cloneCounties(Version $source, Version $version): void
    {
        foreach ($source->counties as $county) {
            VersionCounty::create([
                'version_id' => $version->id,
                'county_id' => $county->county_id,
            ]);
        }
    }

    private function cloneEnsembleOrder(Version $source, Version $version): void
    {
        foreach ($source->ensembleOrder as $order) {
            VersionEnsembleOrder::create([
                'version_id' => $version->id,
                'ensemble_id' => $order->ensemble_id,
                'order_by' => $order->order_by,
            ]);
        }
    }

    private function cloneFees(Version $source, Version $version): void
    {
        $fees = $source->fees;

        if (! $fees) {
            return;
        }

        VersionFee::create([
            'version_id' => $version->id,
            'registration' => $fees->registration,
            'on_site_registration' => $fees->on_site_registration,
            'participation' => $fees->participation,
            'epayment_surcharge' => $fees->epayment_surcharge,
            'housing' => $fees->housing,
        ]);
    }

    private function cloneMembershipRequirement(Version $source, Version $version): void
    {
        $requirement = $source->membershipRequirement;

        if (! $requirement) {
            return;
        }

        $rawValidThru = $requirement->getRawOriginal('valid_thru');

        VersionMembershipRequirement::create([
            'version_id' => $version->id,
            'membership_card' => $requirement->membership_card,
            'valid_thru' => $rawValidThru !== null ? Carbon::parse($rawValidThru)->addYear() : null,
        ]);
    }

    private function cloneObligation(Version $source, Version $version): void
    {
        $obligation = $source->obligation;

        if (! $obligation) {
            return;
        }

        VersionObligation::create([
            'version_id' => $version->id,
            'title' => $obligation->title,
            'body' => $obligation->body,
            'status' => VersionObligationStatus::Draft->value,
            'published_at' => null,
            'published_by_user_id' => null,
        ]);
    }

    private function cloneApplication(Version $source, Version $version): void
    {
        $application = $source->candidateApplication;

        if (! $application) {
            return;
        }

        VersionApplication::create([
            'version_id' => $version->id,
            'student_endorsement_body' => $application->student_endorsement_body,
            'parent_endorsement_body' => $application->parent_endorsement_body,
            'teacher_principal_endorsement_body' => $application->teacher_principal_endorsement_body,
            'schedule_body' => $application->schedule_body,
            'policies_body' => $application->policies_body,
            'status' => VersionApplicationStatus::Draft->value,
            'published_at' => null,
            'published_by_user_id' => null,
        ]);
    }

    private function clonePitchFiles(Version $source, Version $version): void
    {
        foreach ($source->pitchFiles as $pitchFile) {
            VersionPitchFile::create([
                'version_id' => $version->id,
                'voice_part_id' => $pitchFile->voice_part_id,
                'name' => $pitchFile->name,
                'description' => $pitchFile->description,
                'url' => $pitchFile->url,
                'order_by' => $pitchFile->order_by,
            ]);
        }
    }

    private function cloneUploadFiles(Version $source, Version $version): void
    {
        foreach ($source->uploadFiles as $uploadFile) {
            VersionUploadFile::create([
                'version_id' => $version->id,
                'name' => $uploadFile->name,
                'order_by' => $uploadFile->order_by,
            ]);
        }
    }

    private function cloneEpaymentCredential(Version $source, Version $version): void
    {
        $credential = $source->epaymentCredential;

        if (! $credential) {
            return;
        }

        EpaymentCredential::create([
            'version_id' => $version->id,
            'epayment_id' => $credential->epayment_id,
            'secret' => $credential->secret,
        ]);
    }

    private function cloneInvitations(Version $source, Version $version, User $invitedBy): void
    {
        foreach ($source->invitations as $invitation) {
            if ($invitation->getRawOriginal('status') === VersionInvitationStatus::Rejected->value) {
                continue;
            }

            VersionInvitation::create([
                'version_id' => $version->id,
                'teacher_id' => $invitation->teacher_id,
                'status' => VersionInvitationStatus::Invited->value,
                'invited_at' => now(),
                'invited_by_user_id' => $invitedBy->id,
            ]);
        }
    }
}
