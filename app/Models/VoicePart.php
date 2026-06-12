<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VoicePartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'abbr', 'sort_order'])]
class VoicePart extends Model
{
    /** @use HasFactory<VoicePartFactory> */
    use HasFactory;

    /**
     * @param  Builder<VoicePart>  $query
     * @return Builder<VoicePart>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
