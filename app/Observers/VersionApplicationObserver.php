<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\VersionApplication;
use Mews\Purifier\Facades\Purifier;

class VersionApplicationObserver
{
    public function saving(VersionApplication $application): void
    {
        if ($application->isDirty('student_endorsement_body')) {
            $application->student_endorsement_body = Purifier::clean($application->student_endorsement_body, 'obligations');
        }

        if ($application->isDirty('parent_endorsement_body')) {
            $application->parent_endorsement_body = Purifier::clean($application->parent_endorsement_body, 'obligations');
        }

        if ($application->teacher_principal_endorsement_body !== null && $application->isDirty('teacher_principal_endorsement_body')) {
            $application->teacher_principal_endorsement_body = Purifier::clean($application->teacher_principal_endorsement_body, 'obligations');
        }

        if ($application->schedule_body !== null && $application->isDirty('schedule_body')) {
            $application->schedule_body = Purifier::clean($application->schedule_body, 'obligations');
        }

        if ($application->policies_body !== null && $application->isDirty('policies_body')) {
            $application->policies_body = Purifier::clean($application->policies_body, 'obligations');
        }
    }
}
