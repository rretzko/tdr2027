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
        '*sd.*',
        // District-specific domains whose student addresses can't receive
        // outside mail. Teachers on these domains are kept verifiable by
        // the Teacher/User observers, not here.
        'rocktwp.net',
        '*.rocktwp.net',
        // Added 2026-11-10: domains where 0 (or 1) of several self-registered
        // students ever verified, against ~66% for gmail — the verification
        // email isn't getting through. montville.net (50%) was checked and
        // left out: that's ordinary abandonment, not a block.
        'madisonnjps.org',
        '*.madisonnjps.org',
        'mendhamboroschools.org',
        '*.mendhamboroschools.org',
        'msdstudent.org',
        '*.msdstudent.org',
        'nplainfield.org',
        '*.nplainfield.org',
        'panthers.pequannock.org',
        'somervilleschools.org',
        '*.somervilleschools.org',
        'westfieldnjk12.org',
        '*.westfieldnjk12.org',
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
