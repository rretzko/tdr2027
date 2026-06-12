<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NameFormatter;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['email', 'password', 'pronoun_id', 'honorific', 'first_name', 'middle_name', 'last_name', 'suffix_name'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_unverifiable' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function pronoun(): BelongsTo
    {
        return $this->belongsTo(Pronoun::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function phones(): HasMany
    {
        return $this->hasMany(Phone::class);
    }

    public function getSortNameAttribute(): string
    {
        return NameFormatter::buildSortName($this);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('last_name')->orderBy('first_name');
    }
}
