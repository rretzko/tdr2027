<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $order_by
 */
#[Fillable(['version_id', 'ensemble_id', 'order_by'])]
class VersionEnsembleOrder extends Model
{
    protected $table = 'version_ensemble_order';

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return BelongsTo<Ensemble, $this>
     */
    public function ensemble(): BelongsTo
    {
        return $this->belongsTo(Ensemble::class);
    }
}
