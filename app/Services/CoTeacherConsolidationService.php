<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candidate;
use App\Models\CoTeacherGrant;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionCoTeacherConsolidation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The per-Version "record all our shared candidates under one teacher_id"
 * override two co-teachers may set for a school they share
 * (docs/plans/co-teacher-definition.md §4). Deliberately asymmetric:
 * setting a consolidation is retroactive (reassigns existing Candidate rows
 * immediately) and forward-looking (governs future enrollment via
 * resolveTeacherId(), consulted from CandidateService::enroll()); clearing
 * one is neither — it only stops the forward-looking effect, matching this
 * project's existing precedent of never silently un-doing a prior write
 * (e.g. Version Obligations' unpublish leaving existing responses alone).
 */
class CoTeacherConsolidationService
{
    /**
     * Every (school, other teacher) pairing relevant to $teacher in this
     * Version — an active co_teacher_grants row between them (either
     * direction) at a school where at least one of them currently has a
     * Candidate in this Version. Feeds the VersionDashboard consolidation
     * panel; a pairing with no Candidates yet has nothing to consolidate, so
     * it's left out rather than shown as a no-op control.
     *
     * @return Collection<int, covariant array{school: School, otherTeacher: Teacher, existing: ?VersionCoTeacherConsolidation}>
     */
    public function relevantPairings(Teacher $teacher, Version $version): Collection
    {
        $grants = CoTeacherGrant::where('granting_teacher_id', $teacher->id)
            ->orWhere('co_teacher_id', $teacher->id)
            ->get();

        // Deduped (school_id, other_teacher_id) pairs — a plain array, not a
        // Collection::unique() chain, since PHPStan can't reliably re-type a
        // Collection through a later map()/filter() that changes its value
        // shape (cataloged Larastan quirk; see feedback_phpstan_quirks memory).
        $pairs = [];

        foreach ($grants as $grant) {
            $otherTeacherId = $grant->granting_teacher_id === $teacher->id
                ? $grant->co_teacher_id
                : $grant->granting_teacher_id;

            $pairs["{$grant->school_id}-{$otherTeacherId}"] = ['school_id' => $grant->school_id, 'other_teacher_id' => $otherTeacherId];
        }

        $results = [];

        foreach ($pairs as $pair) {
            $hasCandidates = Candidate::where('version_id', $version->id)
                ->where('school_id', $pair['school_id'])
                ->whereIn('teacher_id', [$teacher->id, $pair['other_teacher_id']])
                ->exists();

            if (! $hasCandidates) {
                continue;
            }

            $school = School::find($pair['school_id']);
            $otherTeacher = Teacher::find($pair['other_teacher_id']);

            if ($school === null || $otherTeacher === null) {
                continue;
            }

            [$firstId, $secondId] = VersionCoTeacherConsolidation::canonicalTeacherIds($teacher->id, $pair['other_teacher_id']);

            $existing = VersionCoTeacherConsolidation::where('version_id', $version->id)
                ->where('school_id', $pair['school_id'])
                ->where('first_teacher_id', $firstId)
                ->where('second_teacher_id', $secondId)
                ->first();

            $results[] = ['school' => $school, 'otherTeacher' => $otherTeacher, 'existing' => $existing];
        }

        return new Collection($results);
    }

    /**
     * Sets (or changes) the consolidation for one (version, school,
     * teacherA, teacherB) pairing. Retroactive — every already-existing
     * Candidate at this school in this Version currently recorded under
     * either teacher is reassigned immediately, via a direct bulk update()
     * rather than a per-row save(): unlike TeacherStudentTransferService's
     * student_teacher moves, a teacher_id change on an already-existing
     * Candidate has no observer cascade to preserve.
     */
    public function set(Version $version, School $school, Teacher $teacherA, Teacher $teacherB, Teacher $consolidatedTeacher, User $setByUser): void
    {
        if (! in_array($consolidatedTeacher->id, [$teacherA->id, $teacherB->id], true)) {
            abort(422);
        }

        [$firstId, $secondId] = VersionCoTeacherConsolidation::canonicalTeacherIds($teacherA->id, $teacherB->id);

        VersionCoTeacherConsolidation::updateOrCreate(
            [
                'version_id' => $version->id,
                'school_id' => $school->id,
                'first_teacher_id' => $firstId,
                'second_teacher_id' => $secondId,
            ],
            [
                'consolidated_teacher_id' => $consolidatedTeacher->id,
                'set_by_user_id' => $setByUser->id,
                'set_at' => now(),
            ],
        );

        Candidate::where('version_id', $version->id)
            ->where('school_id', $school->id)
            ->whereIn('teacher_id', [$teacherA->id, $teacherB->id])
            ->where('teacher_id', '!=', $consolidatedTeacher->id)
            ->update(['teacher_id' => $consolidatedTeacher->id]);
    }

    /**
     * The teacher_id a new Candidate enrollment should actually be recorded
     * under — $naturalTeacher unchanged unless an active consolidation
     * redirects it. Called from CandidateService::enroll(), the single
     * choke point every enrollment path (AutoEnrollmentService, manual
     * roster changes) already funnels through, so no caller needs its own
     * awareness of consolidation.
     */
    public function resolveTeacherId(Version $version, int $schoolId, Teacher $naturalTeacher): Teacher
    {
        $consolidation = VersionCoTeacherConsolidation::where('version_id', $version->id)
            ->where('school_id', $schoolId)
            ->where(function (Builder $query) use ($naturalTeacher): void {
                $query->where('first_teacher_id', $naturalTeacher->id)
                    ->orWhere('second_teacher_id', $naturalTeacher->id);
            })
            ->first();

        if ($consolidation === null || $consolidation->consolidated_teacher_id === $naturalTeacher->id) {
            return $naturalTeacher;
        }

        return Teacher::findOrFail($consolidation->consolidated_teacher_id);
    }
}
