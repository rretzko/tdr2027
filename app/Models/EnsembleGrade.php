<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ensemble_id', 'grade'])]
class EnsembleGrade extends Model
{
    /**
     * @return BelongsTo<Ensemble, $this>
     */
    public function ensemble(): BelongsTo
    {
        return $this->belongsTo(Ensemble::class);
    }
}
