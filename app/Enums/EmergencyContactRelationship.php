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
    case GuardianMother = 'guardian_mother';
    case GuardianFather = 'guardian_father';
    case FosterMother = 'foster_mother';
    case FosterFather = 'foster_father';
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
            self::GuardianMother => 'Guardian (Mother)',
            self::GuardianFather => 'Guardian (Father)',
            self::FosterMother => 'Foster Mother',
            self::FosterFather => 'Foster Father',
            self::Other => 'Other',
        };
    }
}
