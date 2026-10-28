<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CoTeacherGrant;
use App\Models\Pivots\SchoolTeacher;
use App\Support\ReplacedTeacherStudentTransfer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Auto-revokes co-teaching grants when either side of a (school, teacher)
 * pair is no longer active+verified there — docs/plans/co-teacher-definition.md
 * §0/§3: "must be active to both teachers" is an ongoing condition, not just
 * a one-time check at grant time, and reactivating later does not restore a
 * revoked grant on its own; it must be re-granted.
 *
 * Also runs ReplacedTeacherStudentTransfer once a replacement link is active
 * and verified — see transferIfReplacementReady().
 *
 * Relies on every school_teacher write going through a real Eloquent
 * update() (never updateExistingPivot(), which is a raw query-builder
 * statement that skips model events entirely) — see the comments on
 * Schools\Index::deactivate()/saveEdit().
 */
class SchoolTeacherObserver
{
    /**
     * Re-verifying a school email reactivates the link. The annual reset
     * (Founder\TeacherVerification::resetAllAndSendEmails()) clears both
     * verified_at and is_active, so a teacher who has left the school drops
     * off student-facing teacher lists until they prove they're still there.
     * Hooked here rather than in each verification path (email link, founder
     * manual verify, AddTeacher, Schools\Index auto-verify) so none can miss it.
     */
    public function updating(SchoolTeacher $pivot): void
    {
        $becameVerified = $pivot->isDirty('verified_at')
            && $pivot->verified_at !== null
            && $pivot->getOriginal('verified_at') === null;

        if ($becameVerified && ! $pivot->is_active) {
            $pivot->is_active = true;
        }
    }

    public function created(SchoolTeacher $pivot): void
    {
        $this->transferIfReplacementReady($pivot);
    }

    public function updated(SchoolTeacher $pivot): void
    {
        $this->revokeGrantsIfNoLongerActive($pivot);

        // Last: the transfer's own saveQuietly() resets wasChanged(), which the
        // grant check above reads.
        $this->transferIfReplacementReady($pivot);
    }

    private function revokeGrantsIfNoLongerActive(SchoolTeacher $pivot): void
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

    /**
     * Hooked here rather than in each verification path (email link, founder
     * manual verify, AddTeacher, Schools\Index auto-verify, director upload) —
     * only two of those five used to call the transfer, which stranded students
     * with departed teachers (docs/plans/replaced-teacher-transfer-fix.md).
     *
     * Runs once per replacement (replacement_transferred_at), plus again if the
     * teacher later changes who they replaced — not on every re-verification.
     */
    private function transferIfReplacementReady(SchoolTeacher $pivot): void
    {
        $pivot->replacementTransferCount = 0;

        $namesReplacement = $pivot->replaced_teacher_id !== null || filled($pivot->replacing_teacher_name);

        if (! $namesReplacement || ! $pivot->is_active || $pivot->verified_at === null) {
            return;
        }

        $replacementChanged = $pivot->wasChanged('replaced_teacher_id') || $pivot->wasChanged('replacing_teacher_name');

        // Read from the row, not the instance: a pivot loaded through a
        // BelongsToMany only carries its withPivot() columns, and a missing
        // stamp must never look like "not transferred yet".
        $alreadyTransferred = SchoolTeacher::whereKey($pivot->getKey())->whereNotNull('replacement_transferred_at')->exists();

        if ($alreadyTransferred && ! $replacementChanged) {
            return;
        }

        $pivot->replacementTransferCount = ReplacedTeacherStudentTransfer::transfer($pivot);
    }
}
