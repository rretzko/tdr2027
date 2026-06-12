<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InstrumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'abbr', 'family', 'in_band', 'in_orchestra', 'sort_order'])]
class Instrument extends Model
{
    /** @use HasFactory<InstrumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'in_band' => 'boolean',
            'in_orchestra' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Instrument>  $query
     * @return Builder<Instrument>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
