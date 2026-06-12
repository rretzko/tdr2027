<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Support\NameFormatter;

class UserObserver
{
    /**
     * @var list<string>
     */
    private const NAME_PARTS = ['honorific', 'first_name', 'middle_name', 'last_name', 'suffix_name'];

    public function saving(User $user): void
    {
        if (! $user->exists || $user->isDirty(self::NAME_PARTS)) {
            $user->name = NameFormatter::buildDisplayName($user);
        }
    }
}
