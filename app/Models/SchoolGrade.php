<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SchoolGradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['school_id', 'grade'])]
class SchoolGrade extends Model
{
    /** @use HasFactory<SchoolGradeFactory> */
    use HasFactory;

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
