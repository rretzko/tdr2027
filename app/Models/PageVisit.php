<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PageVisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Routing\Exceptions\UrlGenerationException;

#[Fillable(['user_id', 'route_name', 'version_id', 'label', 'visit_count', 'last_visited_at'])]
class PageVisit extends Model
{
    /** @use HasFactory<PageVisitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['last_visited_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::make(get: function (): ?string {
            try {
                return route($this->route_name, $this->version_id !== null ? ['version' => $this->version_id] : []);
            } catch (UrlGenerationException) {
                return null;
            }
        });
    }

    /**
     * Base label with the version's short name appended, for version-scoped routes.
     *
     * @return Attribute<string, never>
     */
    protected function displayLabel(): Attribute
    {
        return Attribute::make(get: fn (): string => $this->version !== null ? "{$this->label} — {$this->version->short_name}" : $this->label);
    }
}
