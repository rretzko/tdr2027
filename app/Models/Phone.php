<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhoneType;
use App\Support\PhoneNormalizer;
use Database\Factories\PhoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'raw_number'])]
class Phone extends Model
{
    /** @use HasFactory<PhoneFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PhoneType::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function rawNumber(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => PhoneNormalizer::normalize($value),
        );
    }

    public function getFormattedAttribute(): string
    {
        return PhoneNormalizer::format($this->raw_number) ?? '';
    }
}
