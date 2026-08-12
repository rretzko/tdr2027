<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\CutoffStrategy;
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
    'pitch_file_visibility',
    'score_order', 'cutoff_strategy', 'results_released_at', 'share_results', 'shirt_size', 'teacher_cell', 'upload_type',
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
            'cutoff_strategy' => CutoffStrategy::class,
            'results_released_at' => 'datetime',
            'upload_type' => UploadType::class,
            'birthday' => 'boolean',
            'emergency_contact_name' => 'boolean',
            'emergency_contact_cell' => 'boolean',
            'emergency_contact_email' => 'boolean',
            'height' => 'boolean',
            'home_address' => 'boolean',
            'share_results' => 'boolean',
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
     * Per-Co-Registration-Manager county assignments — a county appears at
     * most once per Version (see the unique constraint), so this is the
     * source of truth for who's responsible for which county, distinct from
     * counties() above (the Version's overall eligible-county list).
     *
     * @return HasMany<CoRegistrationManagerCounty, $this>
     */
    public function coRegistrationManagerCounties(): HasMany
    {
        return $this->hasMany(CoRegistrationManagerCounty::class);
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
     * @return HasMany<VersionInvitationRequest, $this>
     */
    public function invitationRequests(): HasMany
    {
        return $this->hasMany(VersionInvitationRequest::class);
    }

    /**
     * @return HasMany<VersionPitchFile, $this>
     */
    public function pitchFiles(): HasMany
    {
        return $this->hasMany(VersionPitchFile::class)->orderBy('order_by');
    }

    /**
     * @return HasMany<VersionRoom, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(VersionRoom::class)->orderBy('order_by');
    }

    /**
     * @return HasMany<TeacherPayment, $this>
     */
    public function teacherPayments(): HasMany
    {
        return $this->hasMany(TeacherPayment::class);
    }

    /**
     * @return HasMany<CandidatePayment, $this>
     */
    public function candidatePayments(): HasMany
    {
        return $this->hasMany(CandidatePayment::class);
    }

    /**
     * @return HasMany<VersionTeacherPacket, $this>
     */
    public function teacherPackets(): HasMany
    {
        return $this->hasMany(VersionTeacherPacket::class);
    }

    /**
     * @return HasOne<VersionObligation, $this>
     */
    public function obligation(): HasOne
    {
        return $this->hasOne(VersionObligation::class);
    }

    /**
     * Named candidateApplication (not application()) to avoid reading
     * confusingly next to the application_type cast attribute above.
     *
     * @return HasOne<VersionApplication, $this>
     */
    public function candidateApplication(): HasOne
    {
        return $this->hasOne(VersionApplication::class);
    }

    /**
     * This Version's own score category overrides only — not the resolved
     * set. Use availableScoreCategories() to get what actually governs
     * this Version (falls back to the Event's rubric when this is empty).
     *
     * @return HasMany<ScoreCategory, $this>
     */
    public function scoreCategories(): HasMany
    {
        return $this->hasMany(ScoreCategory::class)->orderBy('order_by');
    }

    /**
     * The score category rubric that governs this Version: this Version's
     * own rows if it has any, otherwise the Event's default rubric
     * (version_id IS NULL) — all-or-nothing, not a per-row merge. Same
     * "zero rows means inherit/unrestricted" convention as counties()
     * and event_grades.
     *
     * @return Collection<int, ScoreCategory>
     */
    public function availableScoreCategories(): Collection
    {
        if ($this->scoreCategories()->exists()) {
            return $this->scoreCategories()->get();
        }

        return ScoreCategory::query()
            ->where('event_id', $this->event_id)
            ->whereNull('version_id')
            ->orderBy('order_by')
            ->get();
    }

    /**
     * The score factors belonging to whichever category set
     * availableScoreCategories() resolves to, so factors always match the
     * active rubric without a separate, independently-drifting check.
     *
     * @return Collection<int, ScoreFactor>
     */
    public function availableScoreFactors(): Collection
    {
        return ScoreFactor::query()
            ->whereIn('score_category_id', $this->availableScoreCategories()->pluck('id'))
            ->orderBy('order_by')
            ->get();
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
