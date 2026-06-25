<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrackablePageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['route_name', 'label', 'is_active'])]
class TrackablePage extends Model
{
    /** @use HasFactory<TrackablePageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @param  Builder<TrackablePage>  $query
     * @return Builder<TrackablePage>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
