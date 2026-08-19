<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class NameFormatter
{
    /**
     * Build the display name (the persisted users.name column) from the user's name parts.
     */
    public static function buildDisplayName(User $user): string
    {
        $parts = array_filter([
            $user->honorific,
            $user->first_name,
            $user->middle_name,
            $user->last_name,
            $user->suffix_name,
        ], fn (?string $part): bool => filled($part));

        return implode(' ', $parts);
    }

    /**
     * First+last initials for an avatar-placeholder fallback (e.g. "JD" for
     * Jane Doe) — falls back to just the first name's initial if there's no
     * last name yet (a mid-invite/incomplete record), and to '?' if there's
     * neither.
     */
    public static function initials(User $user): string
    {
        $first = filled($user->first_name) ? mb_strtoupper(mb_substr($user->first_name, 0, 1)) : '';
        $last = filled($user->last_name) ? mb_strtoupper(mb_substr($user->last_name, 0, 1)) : '';

        $initials = $first.$last;

        return $initials !== '' ? $initials : '?';
    }

    /**
     * Build the "Last, Suffix, First Middle (Honorific)" sort name.
     */
    public static function buildSortName(User $user): string
    {
        $segments = [$user->last_name];

        if (filled($user->suffix_name)) {
            $segments[] = $user->suffix_name;
        }

        $firstMiddle = implode(' ', array_filter([
            $user->first_name,
            $user->middle_name,
        ], fn (?string $part): bool => filled($part)));

        if (filled($firstMiddle)) {
            $segments[] = $firstMiddle;
        }

        $sortName = implode(', ', $segments);

        if (filled($user->honorific)) {
            $sortName .= " ({$user->honorific})";
        }

        return $sortName;
    }
}
