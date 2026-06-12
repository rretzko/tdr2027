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
