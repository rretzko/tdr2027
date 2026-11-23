<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Version's end-of-cycle analytics, frozen at Close Audition
 * (VersionAnalyticsSnapshotService). One row per Version; re-Close replaces it.
 *
 * @property Carbon $captured_at
 * @property array<string, mixed> $registration
 * @property array<string, mixed> $adjudication
 * @property array<string, mixed> $selection
 */
#[Fillable(['version_id', 'schema_version', 'captured_at', 'captured_by_user_id', 'registration', 'adjudication', 'selection'])]
class VersionAnalyticsSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'captured_at' => 'datetime',
            'registration' => 'array',
            'adjudication' => 'array',
            'selection' => 'array',
        ];
    }

    /** @return BelongsTo<Version, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
