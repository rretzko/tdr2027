<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BestPhone;
use App\Enums\EmergencyContactRelationship;
use App\Support\PhoneNormalizer;
use Database\Factories\EmergencyContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
            'best_phone' => BestPhone::class,
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

    /**
     * @return Attribute<?string, ?string>
     */
    protected function cellPhone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => PhoneNormalizer::normalize($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function homePhone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => PhoneNormalizer::normalize($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function workPhone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => PhoneNormalizer::normalize($value),
        );
    }
}
