<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'method'])]
class LoginEvent extends Model
{
    const UPDATED_AT = null;

    public static function record(User $user, LoginMethod $method): void
    {
        self::create([
            'user_id' => $user->id,
            'method' => $method,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => LoginMethod::class,
            'created_at' => 'datetime',
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
