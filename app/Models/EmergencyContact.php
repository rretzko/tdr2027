<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmergencyContactRelationship;
use Database\Factories\EmergencyContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['student_id', 'name', 'relationship', 'email', 'cell_phone', 'home_phone', 'work_phone'])]
class EmergencyContact extends Model
{
    /** @use HasFactory<EmergencyContactFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relationship' => EmergencyContactRelationship::class,
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function getPreferredPhoneAttribute(): ?string
    {
        return $this->cell_phone ?? $this->work_phone ?? $this->home_phone;
    }
}
