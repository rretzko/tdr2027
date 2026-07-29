<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JudgeStatus;
use App\Enums\JudgeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An assignment — person + role + room + version — not a person entity.
 * The person is the existing User model (see event-version-orientation.md §5.9).
 */
#[Fillable(['version_id', 'room_id', 'user_id', 'judge_type', 'status'])]
class RoomJudge extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'judge_type' => JudgeType::class,
            'status' => JudgeStatus::class,
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
     * @return BelongsTo<VersionRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(VersionRoom::class, 'room_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
