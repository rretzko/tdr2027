<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\EventStatus;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\UploadType;
use Database\Factories\VersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[Fillable([
    'event_id', 'name', 'short_name', 'senior_class_of', 'status',
    'application_type', 'audition_timeslot', 'audition_type',
    'birthday', 'emergency_contact_name', 'emergency_contact_cell', 'emergency_contact_email',
    'height', 'home_address', 'judge_count',
    'max_registrants', 'max_upper_voice_registrants',
    'pitch_file_visibility', 'release_confidential_results',
    'score_order', 'shirt_size', 'teacher_cell', 'upload_type',
])]
class Version extends Model
{
    /** @use HasFactory<VersionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'application_type' => ApplicationType::class,
            'audition_type' => AuditionType::class,
            'pitch_file_visibility' => PitchFileVisibility::class,
            'score_order' => ScoreOrder::class,
            'upload_type' => UploadType::class,
            'birthday' => 'boolean',
            'emergency_contact_name' => 'boolean',
            'emergency_contact_cell' => 'boolean',
            'emergency_contact_email' => 'boolean',
            'height' => 'boolean',
            'home_address' => 'boolean',
            'release_confidential_results' => 'boolean',
            'shirt_size' => 'boolean',
            'teacher_cell' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<Candidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }

    /**
     * @return HasMany<VersionDate, $this>
     */
    public function dates(): HasMany
    {
        return $this->hasMany(VersionDate::class);
    }

    /**
     * @return HasOne<VersionFee, $this>
     */
    public function fees(): HasOne
    {
        return $this->hasOne(VersionFee::class);
    }

    /**
     * @return HasOne<EpaymentCredential, $this>
     */
    public function epaymentCredential(): HasOne
    {
        return $this->hasOne(EpaymentCredential::class);
    }

    /**
     * @return HasOne<VersionMembershipRequirement, $this>
     */
    public function membershipRequirement(): HasOne
    {
        return $this->hasOne(VersionMembershipRequirement::class);
    }

    /**
     * @return HasMany<VersionCounty, $this>
     */
    public function counties(): HasMany
    {
        return $this->hasMany(VersionCounty::class);
    }

    /**
     * @return HasMany<VersionEnsembleOrder, $this>
     */
    public function ensembleOrder(): HasMany
    {
        return $this->hasMany(VersionEnsembleOrder::class)->orderBy('order_by');
    }

    /**
     * @return HasMany<VersionTimeslot, $this>
     */
    public function timeslots(): HasMany
    {
        return $this->hasMany(VersionTimeslot::class)->orderBy('timeslot');
    }

    /**
     * @return HasMany<VersionUploadFile, $this>
     */
    public function uploadFiles(): HasMany
    {
        return $this->hasMany(VersionUploadFile::class)->orderBy('order_by');
    }

    /**
     * @return HasMany<VersionInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(VersionInvitation::class);
    }

    /**
     * @return HasMany<VersionPitchFile, $this>
     */
    public function pitchFiles(): HasMany
    {
        return $this->hasMany(VersionPitchFile::class)->orderBy('order_by');
    }

    /**
     * Voice parts valid for this Version — the union of voice parts across
     * all of the parent Event's Ensembles. Versions have no direct
     * voice-part relationship of their own; the chain is Version -> Event ->
     * Ensembles -> VoiceParts, via ensemble_voice_parts.
     *
     * @return Collection<int, VoicePart>
     */
    public function availableVoiceParts(): Collection
    {
        return VoicePart::query()
            ->whereIn('id', function ($query): void {
                $query->select('ensemble_voice_parts.voice_part_id')
                    ->from('ensemble_voice_parts')
                    ->join('ensembles', 'ensembles.id', '=', 'ensemble_voice_parts.ensemble_id')
                    ->where('ensembles.event_id', $this->event_id);
            })
            ->ordered()
            ->get();
    }

    /**
     * @return Attribute<int, never>
     */
    protected function uploadFileCount(): Attribute
    {
        return Attribute::make(
            get: fn (): int => $this->uploadFiles->count(),
        );
    }
}
