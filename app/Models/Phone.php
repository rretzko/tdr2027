<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhoneType;
use Database\Factories\PhoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFormattedAttribute(): string
    {
        $main = substr($this->raw_number, 0, 10);
        $extension = substr($this->raw_number, 10);

        $formatted = sprintf(
            '(%s) %s-%s',
            substr($main, 0, 3),
            substr($main, 3, 3),
            substr($main, 6, 4),
        );

        if ($extension !== '') {
            $formatted .= " x{$extension}";
        }

        return $formatted;
    }
}
