<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\HomeAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['student_id', 'address1', 'address2', 'city', 'geo_state', 'zip_code'])]
class HomeAddress extends Model
{
    /** @use HasFactory<HomeAddressFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function getFormattedAttribute(): string
    {
        $lines = array_filter([$this->address1, $this->address2]);

        return implode(', ', [...$lines, "{$this->city}, {$this->geo_state} {$this->zip_code}"]);
    }
}
