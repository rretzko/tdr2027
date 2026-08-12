<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher's roster-wide opt-in to the e-payment feature for one Version —
 * not per-Candidate. Presence of a row with opted_in=true means every
 * Candidate that teacher manages in this Version may use e-payment, once a
 * real gateway integration exists (see EpaymentCredential's own docblock).
 */
#[Fillable(['version_id', 'teacher_id', 'opted_in'])]
class VersionTeacherEpaymentOptIn extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opted_in' => 'boolean',
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
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
