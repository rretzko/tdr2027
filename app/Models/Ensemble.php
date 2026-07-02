<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['event_id', 'name', 'short_name', 'abbreviation'])]
class Ensemble extends Model
{
    use SoftDeletes;

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<EnsembleGrade, $this>
     */
    public function grades(): HasMany
    {
        return $this->hasMany(EnsembleGrade::class);
    }

    /**
     * @return BelongsToMany<VoicePart, $this>
     */
    public function voiceParts(): BelongsToMany
    {
        return $this->belongsToMany(VoicePart::class, 'ensemble_voice_parts')
            ->withTimestamps()
            ->orderBy('sort_order');
    }
}
