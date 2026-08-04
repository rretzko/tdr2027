<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Membership;
use App\Models\School;
use App\Models\Teacher;
use App\Models\Version;
use Carbon\Carbon;

/**
 * Shared per-teacher display resolution for the Registration Manager
 * Reporting Module (event-version-orientation.md §5.10) — every roster
 * report shows "School name", "Teacher phone numbers", and (for Obligated
 * Teachers) membership expiration, so the resolution rules live here once
 * rather than being reimplemented per report.
 */
final class TeacherDisplay
{
    /**
     * The one school shown for a teacher row: prefers the teacher's first
     * active+verified school within the acting user's county scope (if
     * restricted), falling back to their first active+verified school
     * overall — same resolution VersionInvitationEligibilityService::roster()
     * uses for the Version Invitations roster (§5.4).
     *
     * @param  list<int>|null  $countyIds
     */
    public static function primarySchool(Teacher $teacher, ?array $countyIds): ?School
    {
        $schools = $teacher->schools;

        $school = $countyIds !== null
            ? $schools->first(fn (School $s): bool => in_array($s->county_id, $countyIds, true))
            : null;

        return $school ?? $schools->first();
    }

    /**
     * "Teacher phone numbers" — the account's cell_phone (the field actually
     * populated at Teacher registration) plus any additional phones() rows
     * (home/work), displayed raw/unformatted to match this codebase's
     * existing precedent (CandidateDetail shows emergency-contact phones the
     * same way, with no formatting applied).
     */
    public static function phoneNumbers(Teacher $teacher): string
    {
        $numbers = collect([$teacher->user->cell_phone])
            ->filter()
            ->merge($teacher->user->phones->pluck('raw_number'));

        return $numbers->isEmpty() ? '—' : $numbers->implode(', ');
    }

    /**
     * The latest membership_expires_at across the teacher's Membership rows
     * for the Version's root organization — null if the teacher has no
     * membership record. Same max-across-memberships rule as
     * VersionInvitationEligibilityService::roster() (§5.4).
     */
    public static function membershipExpiresAt(Teacher $teacher, Version $version): ?Carbon
    {
        $rootOrgId = $version->event->organization->membershipOrganization()->id;

        $rawExpiresAtDates = $teacher->memberships
            ->where('organization_id', $rootOrgId)
            ->map(fn (Membership $m): mixed => $m->getRawOriginal('membership_expires_at'))
            ->filter()
            ->all();

        if ($rawExpiresAtDates === []) {
            return null;
        }

        return Carbon::parse(max($rawExpiresAtDates));
    }
}
