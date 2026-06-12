<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Enums\Subject;
use Database\Factories\Pivots\SchoolTeacherSubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['school_teacher_id', 'subject'])]
class SchoolTeacherSubject extends Model
{
    /** @use HasFactory<SchoolTeacherSubjectFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $table = 'school_teacher_subject';

    protected $primaryKey = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject' => Subject::class,
        ];
    }

    /**
     * @return BelongsTo<SchoolTeacher, $this>
     */
    public function schoolTeacher(): BelongsTo
    {
        return $this->belongsTo(SchoolTeacher::class);
    }
}
