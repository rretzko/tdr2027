<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CountyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'geostate_id'])]
class County extends Model
{
    /** @use HasFactory<CountyFactory> */
    use HasFactory;

    public function geostate(): BelongsTo
    {
        return $this->belongsTo(Geostate::class);
    }
}
