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
}
