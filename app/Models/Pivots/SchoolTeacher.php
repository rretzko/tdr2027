<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Enums\TeacherRole;
use App\Models\School;
use App\Models\Teacher;
use Database\Factories\Pivots\SchoolTeacherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['school_id', 'teacher_id', 'role', 'replacing_teacher_name', 'is_active', 'school_email', 'verified_at'])]
class SchoolTeacher extends Pivot
{
    /** @use HasFactory<SchoolTeacherFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'school_teacher';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeacherRole::class,
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
        ];
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
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return HasMany<SchoolTeacherSubject, $this>
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(SchoolTeacherSubject::class, 'school_teacher_id');
    }

    /**
     * A school is "Pending" until its school_email is set and verified — until
     * then the teacher hasn't proven their affiliation, so the Active/Inactive
     * toggle is hidden in favor of resolving verification first.
     */
    public function isPending(): bool
    {
        return blank($this->school_email) || blank($this->verified_at);
    }

    /**
     * Sort precedence for status displays: Active, then Pending, then Inactive.
     * Pending takes priority over is_active — an unverified link's eventual
     * Active/Inactive state hasn't been established yet.
     */
    public function statusSortRank(): int
    {
        return match (true) {
            $this->isPending() => 1,
            $this->is_active => 0,
            default => 2,
        };
    }
}
