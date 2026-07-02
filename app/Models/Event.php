<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\Frequency;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'name', 'short_name', 'logo_url', 'logo_alt',
    'status', 'frequency', 'audition_count', 'ensemble_count',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'frequency' => Frequency::class,
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Version, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class);
    }

    /**
     * @return HasMany<Ensemble, $this>
     */
    public function ensembles(): HasMany
    {
        return $this->hasMany(Ensemble::class);
    }

    /**
     * @return HasMany<EventGrade, $this>
     */
    public function grades(): HasMany
    {
        return $this->hasMany(EventGrade::class);
    }

    /**
     * @return HasMany<EventInvitationRequest, $this>
     */
    public function invitationRequests(): HasMany
    {
        return $this->hasMany(EventInvitationRequest::class);
    }
}
