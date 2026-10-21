<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UnsubscribableEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (user, email type) the user has opted out of. Resubscribing
 * deletes the row.
 */
#[Fillable(['user_id', 'email_type'])]
class EmailUnsubscribe extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_type' => UnsubscribableEmail::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
