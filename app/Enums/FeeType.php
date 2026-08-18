<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Registration, participation, and housing are three separate billing
 * events, never combined into one charge — see
 * Version::registrationFeePayable()/participationFeePayable()/
 * housingFeePayable() for the timing gate each one is chargeable under.
 * Participation and housing share the exact same timing (Version closed),
 * so — unlike registration, whose window is mutually exclusive with the
 * other two — both can be simultaneously chargeable; see VersionDashboard's
 * $activeFeeTypes (plural) for how the group "Pay Now" UI handles that.
 */
enum FeeType: string
{
    case Registration = 'registration';
    case Participation = 'participation';
    case Housing = 'housing';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Registration Fee',
            self::Participation => 'Participation Fee',
            self::Housing => 'Housing Fee',
        };
    }
}
