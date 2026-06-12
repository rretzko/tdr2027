<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Models\School;
use App\Models\Teacher;
use Database\Factories\Pivots\SchoolTeacherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['school_id', 'teacher_id', 'is_active', 'school_email', 'verified_at'])]
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
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(SchoolTeacherSubject::class, 'school_teacher_id');
    }
}
