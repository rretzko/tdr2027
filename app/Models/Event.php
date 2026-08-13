<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\Frequency;
use App\Enums\PaymentEnvironment;
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

    /**
     * One row per PaymentEnvironment (sandbox/production) — see
     * EventEpaymentConfig's own docblock. Use activeEpaymentConfig() to
     * resolve the one that matters for the app's current environment.
     *
     * @return HasMany<EventEpaymentConfig, $this>
     */
    public function eventEpaymentConfigs(): HasMany
    {
        return $this->hasMany(EventEpaymentConfig::class);
    }

    /**
     * The credential matching the app-wide services.payments.environment
     * toggle. Not a cacheable relation — its result depends on runtime
     * config, not just this Event's own data, so it can't be eager-loaded
     * via with() the way eventEpaymentConfigs() can.
     */
    public function activeEpaymentConfig(): ?EventEpaymentConfig
    {
        $environment = config('services.payments.environment', PaymentEnvironment::Sandbox->value);

        return $this->eventEpaymentConfigs()->where('environment', $environment)->first();
    }

    /**
     * All of the Event's score categories, including any Version-specific
     * overrides. Use Version::availableScoreCategories() to resolve which
     * set actually governs a given Version.
     *
     * @return HasMany<ScoreCategory, $this>
     */
    public function scoreCategories(): HasMany
    {
        return $this->hasMany(ScoreCategory::class)->orderBy('order_by');
    }
}
