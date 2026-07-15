<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\VersionDateType;
use App\Models\Candidate;
use App\Models\Version;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

final class CandidateApplicationData
{
    public function __construct(
        public readonly string $versionShortName,
        public readonly string $versionName,
        public readonly string $organizationName,
        public readonly ?string $organizationLogoUrl,
        public readonly ?string $organizationLogoAlt,
        public readonly string $candidateFullName,
        public readonly string $candidateFirstName,
        public readonly string $candidatePronounObject,
        public readonly string $candidatePronounPossessive,
        public readonly string $voicePartName,
        public readonly string $grade,
        public readonly string $schoolName,
        public readonly string $schoolShortName,
        public readonly string $teacherFullName,
        public readonly string $teacherCellPhone,
        public readonly string $studentCellPhone,
        public readonly string $emergencyContactName,
        public readonly string $emergencyContactPhone,
        public readonly string $registrationFee,
        public readonly string $onSiteRegistrationFee,
        public readonly string $participationFee,
        public readonly string $epaymentSurchargeFee,
        public readonly string $housingFee,
        public readonly string $applicationDeadline,
        public readonly string $generatedAt,
    ) {}

    public static function fromCandidate(Candidate $candidate): self
    {
        $version = $candidate->version;
        $event = $version->event;
        $teacherUser = $candidate->teacher->user;
        $studentUser = $candidate->student->user;
        $emergencyContact = $candidate->emergencyContact ?? $candidate->student->emergencyContacts->first();
        $programNameParts = explode(' ', $candidate->program_name, 2);

        return new self(
            versionShortName: $version->short_name ?? $version->name,
            versionName: $version->name,
            organizationName: $event->organization->name,
            organizationLogoUrl: self::resolveLogoUrl($event->organization->logo_file_url),
            organizationLogoAlt: $event->organization->logo_file_alt,
            candidateFullName: $candidate->program_name,
            candidateFirstName: $programNameParts[0],
            candidatePronounObject: $studentUser->pronoun !== null ? $studentUser->pronoun->object : 'them',
            candidatePronounPossessive: $studentUser->pronoun !== null ? $studentUser->pronoun->possessive : 'their',
            voicePartName: $candidate->voicePart->name,
            grade: $candidate->student->grade !== null ? (string) $candidate->student->grade : '—',
            schoolName: $candidate->school->name,
            schoolShortName: $candidate->school->short_name,
            teacherFullName: trim("{$teacherUser->first_name} {$teacherUser->last_name}"),
            teacherCellPhone: $teacherUser->cell_phone ?? '—',
            studentCellPhone: $studentUser->cell_phone ?? '—',
            emergencyContactName: $emergencyContact->name ?? '—',
            emergencyContactPhone: $emergencyContact->preferred_phone ?? '—',
            registrationFee: self::formatFee($version->fees?->registration),
            onSiteRegistrationFee: self::formatFee($version->fees?->on_site_registration),
            participationFee: self::formatFee($version->fees?->participation),
            epaymentSurchargeFee: self::formatFee($version->fees?->epayment_surcharge),
            housingFee: self::formatFee($version->fees?->housing),
            applicationDeadline: self::formatDeadline($version),
            generatedAt: now()->format('M j, Y g:ia'),
        );
    }

    public static function placeholder(Version $version): self
    {
        $event = $version->event;
        $sampleVoicePart = $version->availableVoiceParts()->first();
        $voicePartName = $sampleVoicePart !== null ? $sampleVoicePart->name : 'Soprano 1';

        return new self(
            versionShortName: $version->short_name ?? $version->name,
            versionName: $version->name,
            organizationName: $event->organization->name,
            organizationLogoUrl: self::resolveLogoUrl($event->organization->logo_file_url),
            organizationLogoAlt: $event->organization->logo_file_alt,
            candidateFullName: 'Jane A. Sample',
            candidateFirstName: 'Jane',
            candidatePronounObject: 'her',
            candidatePronounPossessive: 'her',
            voicePartName: $voicePartName,
            grade: '10',
            schoolName: 'Sample High School',
            schoolShortName: 'Sample HS',
            teacherFullName: 'Jane Teacher',
            teacherCellPhone: '(555) 555-0100',
            studentCellPhone: '(555) 555-0101',
            emergencyContactName: 'Pat Sample (Parent/Guardian)',
            emergencyContactPhone: '(555) 555-0102',
            registrationFee: self::formatFee($version->fees?->registration),
            onSiteRegistrationFee: self::formatFee($version->fees?->on_site_registration),
            participationFee: self::formatFee($version->fees?->participation),
            epaymentSurchargeFee: self::formatFee($version->fees?->epayment_surcharge),
            housingFee: self::formatFee($version->fees?->housing),
            applicationDeadline: self::formatDeadline($version),
            generatedAt: now()->format('M j, Y g:ia'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toTokenMap(): array
    {
        return [
            'versionShortName' => $this->versionShortName,
            'versionName' => $this->versionName,
            'organizationName' => $this->organizationName,
            'candidateFullName' => $this->candidateFullName,
            'candidateFirstName' => $this->candidateFirstName,
            'candidatePronounObject' => $this->candidatePronounObject,
            'candidatePronounPossessive' => $this->candidatePronounPossessive,
            'voicePartName' => $this->voicePartName,
            'grade' => $this->grade,
            'schoolName' => $this->schoolName,
            'schoolShortName' => $this->schoolShortName,
            'teacherFullName' => $this->teacherFullName,
            'teacherCellPhone' => $this->teacherCellPhone,
            'studentCellPhone' => $this->studentCellPhone,
            'emergencyContactName' => $this->emergencyContactName,
            'emergencyContactPhone' => $this->emergencyContactPhone,
            'registrationFee' => $this->registrationFee,
            'onSiteRegistrationFee' => $this->onSiteRegistrationFee,
            'participationFee' => $this->participationFee,
            'epaymentSurchargeFee' => $this->epaymentSurchargeFee,
            'housingFee' => $this->housingFee,
            'applicationDeadline' => $this->applicationDeadline,
        ];
    }

    /**
     * Human-readable labels for every token in toTokenMap(), keyed identically —
     * drives the "Insert token" picker so its list can never drift from what
     * mergeTokens() actually replaces. Enforced by a matching-keys test.
     * Sorted by token name for display; toTokenMap() builds an explicit
     * key => value map (not order-dependent), so this sort is safe.
     *
     * @return array<string, string>
     */
    public static function tokenDescriptions(): array
    {
        $descriptions = [
            'versionShortName' => 'Version short name',
            'versionName' => 'Version full name',
            'organizationName' => 'Organization name',
            'candidateFullName' => "Candidate's full name",
            'candidateFirstName' => "Candidate's first name",
            'candidatePronounObject' => "Candidate's object pronoun (him/her/them)",
            'candidatePronounPossessive' => "Candidate's possessive pronoun (his/her/their)",
            'voicePartName' => 'Voice part',
            'grade' => 'Grade level',
            'schoolName' => 'School name',
            'schoolShortName' => 'School short name',
            'teacherFullName' => "Teacher's full name",
            'teacherCellPhone' => "Teacher's cell phone",
            'studentCellPhone' => "Candidate's cell phone",
            'emergencyContactName' => 'Emergency contact name',
            'emergencyContactPhone' => 'Emergency contact phone',
            'registrationFee' => 'Registration fee',
            'onSiteRegistrationFee' => 'On-site registration fee',
            'participationFee' => 'Participation fee',
            'epaymentSurchargeFee' => 'E-payment surcharge fee',
            'housingFee' => 'Housing fee',
            'applicationDeadline' => 'Postmark deadline date',
        ];

        ksort($descriptions);

        return $descriptions;
    }

    private static function resolveLogoUrl(?string $key): ?string
    {
        return $key !== null && $key !== ''
            ? Storage::disk('s3')->url($key)
            : null;
    }

    private static function formatFee(?int $cents): string
    {
        return number_format(($cents ?? 0) / 100, 2, '.', '');
    }

    private static function formatDeadline(Version $version): string
    {
        $deadline = $version->dates->firstWhere('date_type', VersionDateType::PostmarkDeadline);

        return $deadline !== null
            ? Carbon::parse($deadline->start_at)->format('M j, Y')
            : '—';
    }
}
