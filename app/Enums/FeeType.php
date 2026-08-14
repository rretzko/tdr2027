<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Registration and participation are two separate billing events, never
 * combined into one charge — see Version::registrationFeePayable()/
 * participationFeePayable() for the timing gate each one is chargeable
 * under.
 */
enum FeeType: string
{
    case Registration = 'registration';
    case Participation = 'participation';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Registration Fee',
            self::Participation => 'Participation Fee',
        };
    }
}
