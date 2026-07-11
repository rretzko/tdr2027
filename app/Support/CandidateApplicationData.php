<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Candidate;
use App\Models\Version;

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
            organizationLogoUrl: $event->logo_url ?? $event->organization->logo_file_url,
            organizationLogoAlt: $event->logo_alt ?? $event->organization->logo_file_alt,
            candidateFullName: $candidate->program_name,
            candidateFirstName: $programNameParts[0],
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
            organizationLogoUrl: $event->logo_url ?? $event->organization->logo_file_url,
            organizationLogoAlt: $event->logo_alt ?? $event->organization->logo_file_alt,
            candidateFullName: 'Jane A. Sample',
            candidateFirstName: 'Jane',
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
        ];
    }

    private static function formatFee(?int $cents): string
    {
        return number_format(($cents ?? 0) / 100, 2, '.', '');
    }
}
