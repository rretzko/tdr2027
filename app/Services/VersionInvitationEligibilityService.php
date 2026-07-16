<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Membership;
use App\Models\School;
use App\Models\Teacher;
use App\Models\Version;
use App\Models\VersionDate;
use App\Models\VersionInvitation;
use App\Support\VersionInvitationRosterRow;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Computes the invitation-eligible teacher pool for a Version (mirrors the
 * "computed, not stored" approach EligibilityService uses for candidates)
 * and pairs it with any existing version_invitations rows for the roster
 * the Event Manager works from. See docs/plans/event-version-orientation.md §5.4.
 */
class VersionInvitationEligibilityService
{
    /**
     * Teachers eligible for this Version's invitation list: at least one
     * active+verified school, and either (a) that school's county is among
     * the Version's configured counties — or the Version has none configured,
     * which is unrestricted — or (b) the teacher holds any Membership record
     * (expired or not) in the Event's root organization.
     *
     * @return Collection<int, Teacher>
     */
    public function eligibleTeachers(Version $version): Collection
    {
        return $this->eligibleTeachersQuery($version)
            ->with([
                'user',
                'schools' => function ($query) {
                    $query->wherePivot('is_active', true)
                        ->whereNotNull('school_teacher.verified_at')
                        ->orderBy('schools.name');
                },
                'memberships',
            ])
            ->get();
    }

    /**
     * Roster rows for the Event Manager's invitation screen: one row per
     * eligible teacher, combining the computed pool with any existing
     * invitation. Absence of a version_invitations row displays as "eligible".
     *
     * @return Collection<int, VersionInvitationRosterRow>
     */
    public function roster(Version $version): Collection
    {
        $rootOrgId = $this->rootOrganizationId($version);
        $countyIds = $version->counties()->pluck('county_id');
        $countyRestricted = $countyIds->isNotEmpty();

        $invitationsByTeacherId = VersionInvitation::where('version_id', $version->id)
            ->get()
            ->keyBy('teacher_id');

        $rows = [];

        foreach ($this->eligibleTeachers($version) as $teacher) {
            $school = $countyRestricted
                ? $teacher->schools->first(fn (School $s): bool => $countyIds->contains($s->county_id))
                : null;
            $school ??= $teacher->schools->first();

            $rawExpiresAtDates = $teacher->memberships
                ->where('organization_id', $rootOrgId)
                ->map(fn (Membership $m): mixed => $m->getRawOriginal('membership_expires_at'))
                ->filter()
                ->all();

            $maxRawExpiresAt = $rawExpiresAtDates === [] ? null : max($rawExpiresAtDates);
            $membershipExpiresAt = $maxRawExpiresAt === null ? null : Carbon::parse($maxRawExpiresAt);

            $invitation = $invitationsByTeacherId->get($teacher->id);
            $status = $invitation === null ? 'eligible' : (string) $invitation->getRawOriginal('status');

            $rows[] = new VersionInvitationRosterRow($teacher, $school, $membershipExpiresAt, $status, $invitation);
        }

        usort($rows, fn (VersionInvitationRosterRow $a, VersionInvitationRosterRow $b): int => $a->teacher->user->sort_name <=> $b->teacher->user->sort_name);

        return collect($rows);
    }

    /**
     * Single-teacher eligibility check — same rules as eligibleTeachers(),
     * without loading the whole pool. Used by VersionInvitationRequestService
     * (§5.8) to gate a teacher's self-service invitation request.
     */
    public function isEligible(Version $version, Teacher $teacher): bool
    {
        return $this->eligibleTeachersQuery($version)->whereKey($teacher->id)->exists();
    }

    /**
     * Whether the Registrations nav entry (Sidebar + Registrations index,
     * §6.2) has anything to show this teacher: an existing invitation, an
     * active candidate, or at least one currently-open Version they're
     * eligible to request. Ordered cheapest-first — the invitation/candidate
     * existence checks cover the common case for a teacher already
     * registering, so the per-Version eligibility loop (the only part that
     * scales with the number of open Versions) only runs when needed.
     */
    public function hasAnyRegistrationAccess(Teacher $teacher): bool
    {
        if (VersionInvitation::where('teacher_id', $teacher->id)->exists()) {
            return true;
        }

        $registrationStatuses = array_map(fn (CandidateStatus $s): string => $s->value, CandidateStatus::registrationStates());

        if (Candidate::where('teacher_id', $teacher->id)->whereIn('status', $registrationStatuses)->exists()) {
            return true;
        }

        foreach (Version::whereIn('id', $this->openForTeacherVersionIds())->get() as $version) {
            if ($this->isEligible($version, $teacher)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Version ids currently open for teacher registration work (an active
     * version_dates row of type "teacher") — shared by hasAnyRegistrationAccess()
     * above and Registrations\Index's "Open for Registration" section so the
     * two can't drift on what "open" means.
     *
     * @return Collection<int, int>
     */
    public function openForTeacherVersionIds(): Collection
    {
        return VersionDate::where('date_type', 'teacher')
            ->where('start_at', '<=', now())
            ->where(function ($q): void {
                $q->whereNull('end_at')->orWhere('end_at', '>=', now());
            })
            ->pluck('version_id');
    }

    /**
     * @return Builder<Teacher>
     */
    private function eligibleTeachersQuery(Version $version): Builder
    {
        $rootOrgId = $this->rootOrganizationId($version);
        $countyIds = $version->counties()->pluck('county_id');

        $query = Teacher::query()
            ->whereHas('schools', function ($q): void {
                $q->where('school_teacher.is_active', true)->whereNotNull('school_teacher.verified_at');
            });

        if ($countyIds->isNotEmpty()) {
            $query->where(function ($q) use ($countyIds, $rootOrgId): void {
                $q->whereHas('schools', function ($sq) use ($countyIds): void {
                    $sq->where('school_teacher.is_active', true)
                        ->whereNotNull('school_teacher.verified_at')
                        ->whereIn('schools.county_id', $countyIds);
                })->orWhereHas('memberships', function ($mq) use ($rootOrgId): void {
                    $mq->where('organization_id', $rootOrgId);
                });
            });
        }

        return $query;
    }

    private function rootOrganizationId(Version $version): int
    {
        return $version->event->organization->membershipOrganization()->id;
    }
}
