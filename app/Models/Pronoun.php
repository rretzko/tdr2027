<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PronounFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['description', 'intensive', 'personal', 'possessive', 'object', 'sort_order'])]
class Pronoun extends Model
{
    /** @use HasFactory<PronounFactory> */
    use HasFactory;

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
