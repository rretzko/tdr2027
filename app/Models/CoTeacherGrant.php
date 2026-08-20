<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['school_id', 'granting_teacher_id', 'co_teacher_id', 'granted_by_user_id'])]
class CoTeacherGrant extends Model
{
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
    public function grantingTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'granting_teacher_id');
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function coTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'co_teacher_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
