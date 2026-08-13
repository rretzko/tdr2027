<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a payment_transactions row originated. See
 * epayment-integration.md §1.1.
 */
enum PaymentSource: string
{
    case CandidateEpayment = 'candidate_epayment';
    case TeacherEpayment = 'teacher_epayment';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::CandidateEpayment => 'Candidate E-Payment',
            self::TeacherEpayment => 'Teacher E-Payment',
            self::Manual => 'Manual',
        };
    }
}
