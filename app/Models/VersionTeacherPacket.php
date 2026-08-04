<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backs the Participating Schools "packet received" checkbox and its batch
 * confirmation email. updateOrCreate'd in place, keyed on
 * (version_id, school_id, teacher_id) — not a history table. Unchecking
 * packet-received clears received_at/received_by_user_id but never touches
 * confirmation_sent_at/confirmation_sent_by_user_id: a confirmation already
 * sent stays sent (see event-version-orientation.md §5.10).
 */
#[Fillable([
    'version_id', 'school_id', 'teacher_id',
    'received_at', 'received_by_user_id',
    'confirmation_sent_at', 'confirmation_sent_by_user_id',
])]
class VersionTeacherPacket extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmationSentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmation_sent_by_user_id');
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    public function isConfirmationSent(): bool
    {
        return $this->confirmation_sent_at !== null;
    }
}
