<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'version_id',
    'school_id',
    'first_teacher_id',
    'second_teacher_id',
    'consolidated_teacher_id',
    'set_by_user_id',
    'set_at',
])]
class VersionCoTeacherConsolidation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'set_at' => 'datetime',
        ];
    }

    /**
     * Canonical [lower, higher] ordering of two teacher ids, so either
     * co-teacher can set a consolidation without needing to know who's
     * "first" — the (version, school, first, second) unique constraint only
     * works if both directions resolve to the same row.
     *
     * @return array{0: int, 1: int}
     */
    public static function canonicalTeacherIds(int $teacherIdA, int $teacherIdB): array
    {
        return $teacherIdA < $teacherIdB ? [$teacherIdA, $teacherIdB] : [$teacherIdB, $teacherIdA];
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function firstTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'first_teacher_id');
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function secondTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'second_teacher_id');
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function consolidatedTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'consolidated_teacher_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }
}
