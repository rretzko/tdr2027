<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How teachers settle their balance — setup question Q4a, mapped onto
 * version_epayment_configs.epayment_teacher + online_payment_required.
 */
enum TeacherPayments: string
{
    case CheckOnly = 'check_only';
    case OnlineOrCheck = 'online_or_check';
    case OnlineOnly = 'online_only';

    public static function fromFlags(bool $epaymentTeacher, bool $onlineRequired): self
    {
        return match (true) {
            ! $epaymentTeacher => self::CheckOnly,
            $onlineRequired => self::OnlineOnly,
            default => self::OnlineOrCheck,
        };
    }

    public function epaymentTeacher(): bool
    {
        return $this !== self::CheckOnly;
    }

    public function onlineRequired(): bool
    {
        return $this === self::OnlineOnly;
    }

    public function label(): string
    {
        return match ($this) {
            self::CheckOnly => 'No — teachers pay by check',
            self::OnlineOrCheck => 'Yes — online or by check',
            self::OnlineOnly => 'Yes — online only',
        };
    }
}
