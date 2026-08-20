<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candidate;
use App\Models\CoTeacherGrant;
use App\Models\School;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "which teachers' Candidates can this teacher
 * see" (docs/plans/co-teacher-definition.md §3). A teacher always sees their
 * own Candidates (candidates.teacher_id === $teacher->id); beyond that, they
 * see another teacher's Candidates at a given school only if that other
 * teacher has an active co_teacher_grants row naming them as the recipient
 * at that same school — access is per-school and strictly directional, never
 * inferred from mere co-presence at a school.
 */
class CoTeacherAccessService
{
    /**
     * Candidate::query() pre-filtered to every Candidate $teacher may
     * view/manage. Safe to chain further where()s onto — the whole
     * ownership-or-granted condition is grouped into a single clause.
     *
     * @return Builder<Candidate>
     */
    public function candidateQuery(Teacher $teacher): Builder
    {
        return Candidate::query()->where(function (Builder $query) use ($teacher): void {
            $query->where('teacher_id', $teacher->id)
                ->orWhere(function (Builder $granted) use ($teacher): void {
                    $granted->whereExists(function (QueryBuilder $exists) use ($teacher): void {
                        $exists->selectRaw('1')
                            ->from('co_teacher_grants')
                            ->whereColumn('co_teacher_grants.school_id', 'candidates.school_id')
                            ->whereColumn('co_teacher_grants.granting_teacher_id', 'candidates.teacher_id')
                            ->where('co_teacher_grants.co_teacher_id', $teacher->id);
                    });
                });
        });
    }

    public function canAccessCandidate(Teacher $teacher, Candidate $candidate): bool
    {
        if ($candidate->teacher_id === $teacher->id) {
            return true;
        }

        return CoTeacherGrant::where('school_id', $candidate->school_id)
            ->where('granting_teacher_id', $candidate->teacher_id)
            ->where('co_teacher_id', $teacher->id)
            ->exists();
    }

    /**
     * Teacher ids whose Candidates $teacher may see — their own id, plus the
     * granting_teacher_id of every active grant made to them, optionally
     * narrowed to one school. Used by call sites that need a plain id list
     * rather than a Candidate query builder (e.g. a Version-list lookup keyed
     * off Candidate::whereIn('teacher_id', ...)).
     *
     * @return Collection<int, int>
     */
    public function visibleTeacherIds(Teacher $teacher, ?int $schoolId = null): Collection
    {
        return CoTeacherGrant::where('co_teacher_id', $teacher->id)
            ->when($schoolId !== null, fn (Builder $query): Builder => $query->where('school_id', $schoolId))
            ->pluck('granting_teacher_id')
            ->push($teacher->id)
            ->unique()
            ->values();
    }

    /**
     * Teachers $teacher could grant a new co-teaching share to at $school —
     * every other teacher active+verified there, minus anyone already
     * granted. Feeds the "grant access to a new co-teacher" picker.
     *
     * @return Collection<int, Teacher>
     */
    public function grantableTeachers(Teacher $teacher, School $school): Collection
    {
        $alreadyGranted = CoTeacherGrant::where('school_id', $school->id)
            ->where('granting_teacher_id', $teacher->id)
            ->pluck('co_teacher_id');

        return $school->teachers()
            ->wherePivot('is_active', true)
            ->wherePivot('verified_at', '!=', null)
            ->where('teachers.id', '!=', $teacher->id)
            ->whereNotIn('teachers.id', $alreadyGranted)
            ->with('user')
            ->get();
    }
}
