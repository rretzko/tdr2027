<?php

declare(strict_types=1);

namespace App\Enums;

enum EmergencyContactRelationship: string
{
    case Mother = 'mother';
    case Father = 'father';
    case StepMother = 'step_mother';
    case StepFather = 'step_father';
    case Grandmother = 'grandmother';
    case Grandfather = 'grandfather';
    case Guardian = 'guardian';
    case Sibling = 'sibling';
    case Aunt = 'aunt';
    case Uncle = 'uncle';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Mother => 'Mother',
            self::Father => 'Father',
            self::StepMother => 'Step-Mother',
            self::StepFather => 'Step-Father',
            self::Grandmother => 'Grandmother',
            self::Grandfather => 'Grandfather',
            self::Guardian => 'Guardian',
            self::Sibling => 'Sibling',
            self::Aunt => 'Aunt',
            self::Uncle => 'Uncle',
            self::Other => 'Other',
        };
    }
}
