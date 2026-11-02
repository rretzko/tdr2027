<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Teacher;

class TeacherObserver
{
    /**
     * Teachers must always verify their own email, so clear any
     * email_unverifiable flag the user picked up beforehand (e.g. an
     * existing student account on a school domain becoming a teacher).
     */
    public function created(Teacher $teacher): void
    {
        $user = $teacher->user;

        if ($user !== null && $user->email_unverifiable) {
            $user->forceFill(['email_unverifiable' => false])->save();
        }
    }
}
