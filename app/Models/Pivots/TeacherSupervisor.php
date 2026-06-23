<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Models\Organization;
use App\Models\Teacher;
use App\Support\PhoneNormalizer;
use Database\Factories\Pivots\TeacherSupervisorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'teacher_id', 'supervisor_name', 'supervisor_email', 'supervisory_cell_phone'])]
class TeacherSupervisor extends Model
{
    /** @use HasFactory<TeacherSupervisorFactory> */
    use HasFactory;

    protected $table = 'teacher_supervisors';

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function supervisoryCellPhone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => PhoneNormalizer::normalize($value),
        );
    }
}
