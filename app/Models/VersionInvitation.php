<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VersionInvitationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['version_id', 'teacher_id', 'status', 'invited_at', 'invited_by_user_id'])]
class VersionInvitation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VersionInvitationStatus::class,
            'invited_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return HasOne<VersionObligationResponse, $this>
     */
    public function obligationResponse(): HasOne
    {
        return $this->hasOne(VersionObligationResponse::class);
    }
}
