<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\VersionScorecardMetrics;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (Version, capture date) of the Monday Morning Scorecard
 * numbers, written by SendMondayMorningScorecardEmails so trends can be
 * charted later. VersionScorecardService only computes current state, so
 * history exists only from the first snapshot forward. Fee amounts are cents.
 */
#[Fillable([
    'version_id',
    'captured_on',
    'invited_teachers',
    'invited_schools',
    'eligible_students',
    'obligated_teachers',
    'obligated_schools',
    'engaged_students',
    'registered_teachers',
    'registered_schools',
    'registered_students',
    'registration_fees_due_cents',
    'registration_fees_paid_cents',
    'registration_fees_outstanding_cents',
])]
class VersionScorecardSnapshot extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * Upserts on (version_id, captured_on) so a re-run of the scheduled
     * command on the same day overwrites rather than duplicates.
     */
    public static function record(Version $version, VersionScorecardMetrics $metrics, CarbonInterface $capturedOn): self
    {
        return self::updateOrCreate(
            ['version_id' => $version->id, 'captured_on' => $capturedOn->copy()->startOfDay()],
            [
                'invited_teachers' => $metrics->invitedTeachers,
                'invited_schools' => $metrics->invitedSchools,
                'eligible_students' => $metrics->eligibleStudents,
                'obligated_teachers' => $metrics->obligatedTeachers,
                'obligated_schools' => $metrics->obligatedSchools,
                'engaged_students' => $metrics->engagedStudents,
                'registered_teachers' => $metrics->registeredTeachers,
                'registered_schools' => $metrics->registeredSchools,
                'registered_students' => $metrics->registeredStudents,
                'registration_fees_due_cents' => $metrics->registrationFeesDueCents,
                'registration_fees_paid_cents' => $metrics->registrationFeesPaidCents,
                'registration_fees_outstanding_cents' => $metrics->registrationFeesOutstandingCents,
            ],
        );
    }
}
