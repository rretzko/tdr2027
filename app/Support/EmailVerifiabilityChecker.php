<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class EmailVerifiabilityChecker
{
    /**
     * Glob patterns matched against the lowercased email domain.
     *
     * @var list<string>
     */
    private const DOMAIN_PATTERNS = [
        '*.student.*',
        '*.k12.*',
        '*.school.*',
        '*.*sd*.*',
    ];

    /**
     * Determine whether an email address is likely unverifiable (e.g. a school-issued
     * or StudentFolder.info-generated address that the student cannot receive mail at).
     */
    public static function isLikelyUnverifiable(string $email): bool
    {
        $domain = strtolower(Str::after($email, '@'));

        if (str_ends_with($domain, 'studentfolder.info')) {
            return true;
        }

        foreach (self::DOMAIN_PATTERNS as $pattern) {
            if (Str::is($pattern, $domain)) {
                return true;
            }
        }

        return false;
    }
}
