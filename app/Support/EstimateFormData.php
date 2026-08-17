<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CandidateStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Candidate;
use App\Models\Membership;
use App\Models\PaymentAllocation;
use App\Models\School;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionMailToAddress;
use App\Models\VoicePart;
use App\Services\MailToAddressResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Everything the Estimate Form (screen preview and PDF alike) needs for one
 * (Version, School, Teacher) — event-version-orientation.md §5.13. Built
 * once and shared by both renderers so they can never drift, mirroring how
 * CandidateApplicationData feeds the Candidate Application's preview/PDF.
 */
final class EstimateFormData
{
    public function __construct(
        public readonly Version $version,
        public readonly School $school,
        public readonly Teacher $teacher,
        /** @var Collection<int, Candidate> registered candidates, ordered by student last/first name, capped at max_registrants */
        public readonly Collection $candidates,
        public readonly bool $truncated,
        /** @var Collection<int, array{voicePart: VoicePart, count: int}> */
        public readonly Collection $voicePartCounts,
        public readonly int $registrationCents,
        public readonly int $feeSubtotalCents,
        public readonly int $ePaymentsCents,
        public readonly int $balanceDueCents,
        public readonly bool $membershipCardRequired,
        public readonly ?string $membershipCardImageUrl,
        public readonly ?VersionMailToAddress $mailToAddress,
        public readonly ?string $organizationLogoUrl,
        public readonly ?string $organizationLogoAlt,
        public readonly string $generatedAt,
    ) {}

    public static function build(Version $version, School $school, Teacher $teacher, MailToAddressResolver $mailToResolver): self
    {
        $fees = $version->fees;
        $registrationCents = $fees !== null ? (int) $fees->registration : 0;

        $allRegistered = Candidate::where('version_id', $version->id)
            ->where('school_id', $school->id)
            ->where('teacher_id', $teacher->id)
            ->where('status', CandidateStatus::Registered->value)
            ->with(['student.user', 'voicePart'])
            ->get()
            ->sortBy(fn (Candidate $c): string => mb_strtolower($c->student->user->sort_name))
            ->values();

        $maxRegistrants = $version->max_registrants;
        $truncated = $maxRegistrants !== null && $maxRegistrants > 0 && $allRegistered->count() > $maxRegistrants;
        $candidates = $truncated ? $allRegistered->take($maxRegistrants)->values() : $allRegistered;

        $voicePartCounts = $version->availableVoiceParts()
            ->reject(fn (VoicePart $vp): bool => $vp->abbr === 'ALL')
            ->map(fn (VoicePart $vp): array => [
                'voicePart' => $vp,
                'count' => $candidates->filter(fn (Candidate $c): bool => $c->voice_part_id === $vp->id)->count(),
            ]);

        $ePaymentsCents = (int) PaymentAllocation::whereIn('candidate_id', $candidates->pluck('id'))
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', PaymentTransactionStatus::Completed->value))
            ->sum('amount');

        $feeSubtotalCents = $candidates->count() * $registrationCents;

        $membershipCardRequired = (bool) ($version->membershipRequirement?->membership_card);
        $membershipCardImageUrl = null;

        if ($membershipCardRequired) {
            $rootOrganization = $version->event->organization->membershipOrganization();

            $membership = Membership::where('teacher_id', $teacher->id)
                ->where('organization_id', $rootOrganization->id)
                ->whereNotNull('membership_card')
                ->first();

            $membershipCardImageUrl = $membership !== null ? self::resolveImageUrl($membership->membership_card) : null;
        }

        $organization = $version->event->organization;

        return new self(
            version: $version,
            school: $school,
            teacher: $teacher,
            candidates: $candidates,
            truncated: $truncated,
            voicePartCounts: $voicePartCounts,
            registrationCents: $registrationCents,
            feeSubtotalCents: $feeSubtotalCents,
            ePaymentsCents: $ePaymentsCents,
            balanceDueCents: $feeSubtotalCents - $ePaymentsCents,
            membershipCardRequired: $membershipCardRequired,
            membershipCardImageUrl: $membershipCardImageUrl,
            mailToAddress: $mailToResolver->resolve($version, $school),
            organizationLogoUrl: self::resolveImageUrl($organization->logo_file_url),
            organizationLogoAlt: $organization->logo_file_alt,
            // America/New_York per §0.3 — ordinal day, matching the source
            // doc's "Downloaded on: Saturday, August 15th, 2026 @ 06:59:26 am".
            generatedAt: now()->timezone('America/New_York')->format('l, F jS, Y \@ h:i:s a'),
        );
    }

    private static function resolveImageUrl(?string $key): ?string
    {
        // Same private-bucket signed-URL requirement as every other S3-backed
        // image in this codebase (Recordings, Pitch Files, Application logos,
        // §9 item 34) — a plain ->url() 403s silently.
        return $key !== null && $key !== ''
            ? Storage::disk('s3')->temporaryUrl($key, now()->addMinutes(30))
            : null;
    }
}
