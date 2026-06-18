<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class CommercialEmailDomains
{
    /**
     * Free/personal webmail providers that aren't acceptable as a school email,
     * since school_email is used to gate access to student data and needs to be
     * tied to a domain the school itself controls.
     *
     * @var list<string>
     */
    public const DOMAINS = [
        'gmail.com', 'googlemail.com',
        'yahoo.com', 'ymail.com', 'rocketmail.com',
        'hotmail.com', 'outlook.com', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com',
        'aol.com',
        'protonmail.com', 'proton.me',
        'mail.com', 'gmx.com', 'gmx.us',
        'yandex.com',
        'zoho.com',
        'fastmail.com',
        'inbox.com',
        'hushmail.com',
    ];

    public static function matches(string $email): bool
    {
        $domain = mb_strtolower(Str::after($email, '@'));

        return in_array($domain, self::DOMAINS, true);
    }
}
