<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ObligationDecision;
use App\Observers\VersionObligationResponseObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['version_invitation_id', 'version_obligation_id', 'decision', 'decided_at', 'obligation_snapshot'])]
#[ObservedBy(VersionObligationResponseObserver::class)]
class VersionObligationResponse extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => ObligationDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VersionInvitation, $this>
     */
    public function versionInvitation(): BelongsTo
    {
        return $this->belongsTo(VersionInvitation::class);
    }

    /**
     * @return BelongsTo<VersionObligation, $this>
     */
    public function versionObligation(): BelongsTo
    {
        return $this->belongsTo(VersionObligation::class);
    }

    public function isAccepted(): bool
    {
        return $this->getRawOriginal('decision') === ObligationDecision::Accepted->value;
    }
}
