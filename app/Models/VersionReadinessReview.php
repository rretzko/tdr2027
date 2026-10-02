<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReadinessReviewState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (Version, readiness item key) — see
 * App\Services\Readiness\VersionReadiness and docs/plans/version-readiness.md §3.
 */
#[Fillable(['version_id', 'item_key', 'state', 'user_id', 'reviewed_at'])]
class VersionReadinessReview extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ReadinessReviewState::class,
            'reviewed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
