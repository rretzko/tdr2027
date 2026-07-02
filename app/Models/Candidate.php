<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CandidateStatus;
use App\Observers\CandidateObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'student_id', 'version_id', 'school_id', 'teacher_id',
    'voice_part_id', 'status', 'program_name', 'emergency_contact_id',
])]
#[ObservedBy(CandidateObserver::class)]
class Candidate extends Model
{
    public $incrementing = false;

    protected $keyType = 'integer';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CandidateStatus::class,
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
}
