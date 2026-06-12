<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Models\School;
use App\Models\Student;
use Database\Factories\Pivots\SchoolStudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['student_id', 'school_id', 'is_active', 'class_of'])]
class SchoolStudent extends Pivot
{
    /** @use HasFactory<SchoolStudentFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'school_student';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
