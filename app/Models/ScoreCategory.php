<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $order_by
 */
#[Fillable(['event_id', 'version_id', 'description', 'order_by'])]
class ScoreCategory extends Model
{
    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return HasMany<ScoreFactor, $this>
     */
    public function scoreFactors(): HasMany
    {
        return $this->hasMany(ScoreFactor::class)->orderBy('order_by');
    }
}
