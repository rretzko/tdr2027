<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GeostateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['country_abbr', 'name', 'abbr'])]
class Geostate extends Model
{
    /** @use HasFactory<GeostateFactory> */
    use HasFactory;

    public function counties(): HasMany
    {
        return $this->hasMany(County::class);
    }
}
