<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NameFormatter;
use App\Support\PhoneNormalizer;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['email', 'cell_phone', 'password', 'pronoun_id', 'honorific', 'first_name', 'middle_name', 'last_name', 'suffix_name'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, MustVerifyEmail, Notifiable, TwoFactorAuthenticatable;

    /**
     * Determine if the user has verified their email address.
     *
     * Accounts flagged as `email_unverifiable` (e.g. school-issued SFDI
     * addresses or auto-generated SFDI addresses) are treated as verified
     * so they are never sent a verification email and are not blocked by
     * the `verified` middleware.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_unverifiable || ! is_null($this->email_verified_at);
    }

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

    /**
     * @return BelongsTo<Pronoun, $this>
     */
    public function pronoun(): BelongsTo
    {
        return $this->belongsTo(Pronoun::class);
    }

    /**
     * @return HasOne<Student, $this>
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * @return HasOne<Teacher, $this>
     */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    /**
     * @return HasMany<Phone, $this>
     */
    public function phones(): HasMany
    {
        return $this->hasMany(Phone::class);
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function avatarUrl(): ?string
    {
        return $this->socialAccounts()->whereNotNull('provider_avatar')->value('provider_avatar');
    }

    /**
     * The Founder account is hard-coded to a single, specific email rather than
     * a Spatie role — there is exactly one Founder and it isn't expected to grow
     * into a general-purpose admin role.
     */
    public function isFounder(): bool
    {
        return $this->email === 'rick@mfrholdings.com';
    }

    public function getSortNameAttribute(): string
    {
        return NameFormatter::buildSortName($this);
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function cellPhone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => PhoneNormalizer::normalize($value),
        );
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
