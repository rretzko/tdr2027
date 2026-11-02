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

        // Teachers must always verify their own email — never let a
        // student-oriented code path flag a teacher's account as unverifiable.
        if ($user->exists && $user->email_unverifiable && $user->isDirty('email_unverifiable') && $user->teacher()->exists()) {
            $user->email_unverifiable = false;
        }
    }
}
