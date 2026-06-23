<?php

declare(strict_types=1);

namespace App\Support;

final class PhoneNormalizer
{
    /**
     * Strip every non-digit character so phone numbers are always stored
     * in a single canonical format, regardless of how they were typed or
     * imported (e.g. "(201) 755-4083" and "2017554083" must compare equal).
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        return $digits === '' ? null : $digits;
    }
}
