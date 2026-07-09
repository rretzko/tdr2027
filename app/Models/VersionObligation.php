<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VersionObligationStatus;
use App\Observers\VersionObligationObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['version_id', 'title', 'body', 'status', 'published_at', 'published_by_user_id'])]
#[ObservedBy(VersionObligationObserver::class)]
class VersionObligation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VersionObligationStatus::class,
            'published_at' => 'datetime',
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
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function isPublished(): bool
    {
        return $this->getRawOriginal('status') === VersionObligationStatus::Published->value;
    }

    public static function mergeTokens(string $body, Version $version): string
    {
        return str_replace(
            ['{{versionShortName}}', '{{versionName}}'],
            [$version->short_name, $version->name],
            $body,
        );
    }

    /**
     * @return HasMany<VersionObligationResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(VersionObligationResponse::class);
    }
}
