<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Candidate;
use App\Models\Version;

/**
 * Where a teacher lands after a hosted checkout. A closed Version's payments
 * are made from the Participation Fees page, so the payer returns there.
 */
final class CheckoutReturnUrl
{
    public static function teacher(Version $version, ?Candidate $candidate): string
    {
        return match (true) {
            $version->collectsParticipationFees() => route('registrations.participation-fees', ['version' => $version->id]),
            $candidate !== null => route('registrations.candidate', ['version' => $version->id, 'candidate' => $candidate->id]),
            default => route('registrations.version', ['version' => $version->id]),
        };
    }
}
