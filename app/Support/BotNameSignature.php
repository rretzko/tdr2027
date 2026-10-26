<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The registration bot active since Sep 2026 fills every visible text input
 * with Faker names and copies the same value into both middle name and
 * suffix ("Albina Schinner" / "Albina Schinner"). No real registration in
 * the data has ever matched this, so it's used both to drop the submission
 * at registration time and to recognize already-created bot accounts.
 */
class BotNameSignature
{
    public static function matches(?string $middleName, ?string $suffixName): bool
    {
        $middle = mb_strtolower(trim((string) $middleName));

        return $middle !== '' && $middle === mb_strtolower(trim((string) $suffixName));
    }
}
