<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApplicationType;
use App\Enums\CandidateStatus;
use App\Observers\CandidateObserver;
use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'student_id', 'version_id', 'school_id', 'teacher_id',
    'voice_part_id', 'accepted_ensemble_id', 'status', 'program_name', 'emergency_contact_id',
    'application_certified_at', 'application_certified_by_user_id',
    'application_candidate_signed_at', 'application_parent_signed_at',
])]
#[ObservedBy(CandidateObserver::class)]
class Candidate extends Model
{
    /** @use HasFactory<CandidateFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'integer';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CandidateStatus::class,
            'application_certified_at' => 'datetime',
            'application_candidate_signed_at' => 'datetime',
            'application_parent_signed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<VoicePart, $this>
     */
    public function voicePart(): BelongsTo
    {
        return $this->belongsTo(VoicePart::class);
    }

    /**
     * The Ensemble this candidate was assigned to by Ensemble Cut-offs —
     * null until accepted (or if not_accepted).
     *
     * @return BelongsTo<Ensemble, $this>
     */
    public function acceptedEnsemble(): BelongsTo
    {
        return $this->belongsTo(Ensemble::class, 'accepted_ensemble_id');
    }

    /**
     * @return BelongsTo<EmergencyContact, $this>
     */
    public function emergencyContact(): BelongsTo
    {
        return $this->belongsTo(EmergencyContact::class);
    }

    /**
     * @return HasMany<CandidateStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(CandidateStatusHistory::class)->orderBy('created_at');
    }

    /**
     * @return HasMany<CandidatePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CandidatePayment::class);
    }

    /**
     * The frozen score tally written at cut-off-decision time
     * (EnsembleCutoffService::acceptCandidate()/rejectCandidate()) — null
     * until this Candidate has been resolved by Ensemble Cut-offs.
     *
     * @return HasOne<AuditionResult, $this>
     */
    public function auditionResult(): HasOne
    {
        return $this->hasOne(AuditionResult::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function applicationCertifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'application_certified_by_user_id');
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isApplicationCertified(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => match ($this->version->getRawOriginal('application_type')) {
                ApplicationType::Pdf->value => $this->application_certified_at !== null,
                default => $this->application_candidate_signed_at !== null
                    && $this->application_parent_signed_at !== null,
            },
        );
    }
}
