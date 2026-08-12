<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ApplicationType;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\Version;

trait HasCandidateChecklist
{
    /**
     * Returns the list of checklist item definitions for this version — each is
     * a label + a closure that takes a Candidate and returns bool (done or not).
     *
     * @return list<array{label: string, check: \Closure(Candidate): bool}>
     */
    public function checklistDefs(Version $version): array
    {
        $items = [
            [
                'label' => 'Program name',
                'check' => fn (Candidate $c): bool => $c->program_name !== '',
            ],
        ];

        if ((bool) $version->emergency_contact_name) {
            $items[] = [
                'label' => 'Emergency contact',
                // "Present" isn't enough on its own — a qualifying contact
                // must also carry whichever of cell/email this Version
                // separately requires (versions.emergency_contact_cell/
                // emergency_contact_email), matching the same two flags
                // CandidateDetail::saveEmergencyContact() validates against.
                'check' => fn (Candidate $c): bool => $c->student->emergencyContacts->contains(
                    fn (EmergencyContact $ec): bool => (! (bool) $version->emergency_contact_cell || $ec->cell_phone !== null)
                        && (! (bool) $version->emergency_contact_email || $ec->email !== null),
                ),
            ];
        }

        if ((bool) $version->birthday) {
            $items[] = [
                'label' => 'Birthday',
                'check' => fn (Candidate $c): bool => $c->student->birthday !== null,
            ];
        }

        if ((bool) $version->height) {
            $items[] = [
                'label' => 'Height',
                'check' => fn (Candidate $c): bool => $c->student->height !== null,
            ];
        }

        if ((bool) $version->home_address) {
            $items[] = [
                'label' => 'Home address',
                'check' => fn (Candidate $c): bool => $c->student->homeAddress !== null,
            ];
        }

        if ((bool) $version->shirt_size) {
            $items[] = [
                'label' => 'Shirt size',
                'check' => fn (Candidate $c): bool => $c->student->getRawOriginal('shirt_size') !== null,
            ];
        }

        if ($version->candidateApplication?->isPublished()) {
            if ($version->getRawOriginal('application_type') === ApplicationType::Pdf->value) {
                $items[] = [
                    'label' => 'Signatures certified',
                    'check' => fn (Candidate $c): bool => $c->application_certified_at !== null,
                ];
            } else {
                $items[] = [
                    'label' => 'Candidate signed',
                    'check' => fn (Candidate $c): bool => $c->application_candidate_signed_at !== null,
                ];
                $items[] = [
                    'label' => 'Parent signed',
                    'check' => fn (Candidate $c): bool => $c->application_parent_signed_at !== null,
                ];
            }
        }

        return $items;
    }
}
