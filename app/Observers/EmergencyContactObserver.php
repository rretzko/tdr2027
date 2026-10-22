<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Candidate;
use App\Models\EmergencyContact;

/**
 * Keeps candidates.emergency_contact_id pointed at a live contact. Enrollment
 * (CandidateService::enroll()) defaults the link to the student's most
 * recently updated contact, but a student with no contacts yet is enrolled
 * with a NULL link — and contacts can be added from three places
 * (Registrations\CandidateDetail, Students\Index, Sfdi\EmergencyContacts).
 * Handling it here covers all of them:
 * - created(): fills every still-unlinked candidate of that student
 * - deleted(): re-points candidates linked to the removed contact at the
 *   student's most recently updated remaining contact (or NULL if none) —
 *   soft deletes don't trigger the FK's nullOnDelete, and a trashed contact
 *   resolves to null through Candidate::emergencyContact() anyway.
 *
 * Writes via the query builder on purpose: only the link changes, so
 * CandidateObserver's status-history hooks have nothing to record.
 */
class EmergencyContactObserver
{
    public function created(EmergencyContact $contact): void
    {
        Candidate::where('student_id', $contact->student_id)
            ->whereNull('emergency_contact_id')
            ->update(['emergency_contact_id' => $contact->id]);
    }

    public function deleted(EmergencyContact $contact): void
    {
        Candidate::where('student_id', $contact->student_id)
            ->where('emergency_contact_id', $contact->id)
            ->update(['emergency_contact_id' => EmergencyContact::latestIdForStudent($contact->student_id)]);
    }
}
