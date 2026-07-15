<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VersionApplicationStatus;
use App\Observers\VersionApplicationObserver;
use App\Support\CandidateApplicationData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'version_id', 'student_endorsement_body', 'parent_endorsement_body',
    'teacher_principal_endorsement_body', 'schedule_body', 'policies_body',
    'status', 'published_at', 'published_by_user_id',
])]
#[ObservedBy(VersionApplicationObserver::class)]
class VersionApplication extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VersionApplicationStatus::class,
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
        return $this->getRawOriginal('status') === VersionApplicationStatus::Published->value;
    }

    public static function mergeTokens(string $body, CandidateApplicationData $data): string
    {
        $tokens = $data->toTokenMap();

        return str_replace(
            array_map(fn (string $key): string => "{{{$key}}}", array_keys($tokens)),
            array_values($tokens),
            $body,
        );
    }
}
