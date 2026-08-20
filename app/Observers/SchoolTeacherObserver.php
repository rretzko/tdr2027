<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CoTeacherGrant;
use App\Models\Pivots\SchoolTeacher;
use Illuminate\Database\Eloquent\Builder;

/**
 * Auto-revokes co-teaching grants when either side of a (school, teacher)
 * pair is no longer active+verified there — docs/plans/co-teacher-definition.md
 * §0/§3: "must be active to both teachers" is an ongoing condition, not just
 * a one-time check at grant time, and reactivating later does not restore a
 * revoked grant on its own; it must be re-granted.
 *
 * Relies on every school_teacher write going through a real Eloquent
 * update() (never updateExistingPivot(), which is a raw query-builder
 * statement that skips model events entirely) — see the comments on
 * Schools\Index::deactivate()/saveEdit().
 */
class SchoolTeacherObserver
{
    public function updated(SchoolTeacher $pivot): void
    {
        $wentInactive = $pivot->wasChanged('is_active') && ! $pivot->is_active;
        $wentUnverified = $pivot->wasChanged('verified_at') && $pivot->verified_at === null;

        if (! $wentInactive && ! $wentUnverified) {
            return;
        }

        CoTeacherGrant::where('school_id', $pivot->school_id)
            ->where(function (Builder $query) use ($pivot): void {
                $query->where('granting_teacher_id', $pivot->teacher_id)
                    ->orWhere('co_teacher_id', $pivot->teacher_id);
            })
            ->delete();
    }
}
