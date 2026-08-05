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

    /**
     * Renders a normalized (digits-only) number as "(201) 755-4083", with
     * any digits past the tenth appended as an extension (" x1234") — the
     * single formatting rule every view/PDF/CSV display should share.
     */
    public static function format(?string $value): ?string
    {
        $digits = self::normalize($value);

        if ($digits === null) {
            return null;
        }

        $main = substr($digits, 0, 10);
        $extension = substr($digits, 10);

        $formatted = sprintf(
            '(%s) %s-%s',
            substr($main, 0, 3),
            substr($main, 3, 3),
            substr($main, 6, 4),
        );

        return $extension === '' ? $formatted : "{$formatted} x{$extension}";
    }
}
