<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\CandidateUploadStatus;
use App\Enums\UploadType;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\EmergencyContact;
use App\Models\Version;

trait HasCandidateChecklist
{
    /**
     * Returns the list of checklist item definitions for this version — each is
     * a label + a closure that takes a Candidate and returns bool (done or not),
     * plus an optional `partial` closure (only ever consulted when `check`
     * returns false) for a third, amber "in progress" rendering state.
     *
     * An item with `gate => true` is required for Registered but isn't
     * progress: it doesn't count toward the Eligible → Pending threshold in
     * CandidateService::recalculateStatus(), since it's true from the moment
     * a typical Candidate enrolls.
     *
     * @return list<array{label: string, check: \Closure(Candidate): bool, partial?: \Closure(Candidate): bool, gate?: bool}>
     */
    public function checklistDefs(Version $version): array
    {
        $items = [
            [
                'label' => 'Program name',
                'check' => fn (Candidate $c): bool => $c->program_name !== '',
            ],
        ];

        // Voice part must be one this student's grade is offered — the same
        // rule the edit modals and auto-enrollment enforce. Catches legacy /
        // pre-2026-09-21 enrollments and later changes (a grade correction, or
        // a voice part removed from an Ensemble) that leave a Candidate on a
        // part nothing offers them. Skipped when the Version has no Ensemble
        // voice parts at all, which would otherwise fail every Candidate.
        if ($version->availableVoiceParts()->isNotEmpty()) {
            /** @var array<string, list<int>> $offeredByGrade */
            $offeredByGrade = [];

            $items[] = [
                'label' => 'Voice part valid for grade',
                'check' => function (Candidate $c) use ($version, &$offeredByGrade): bool {
                    $grade = $c->student->grade;
                    $offeredByGrade[(string) $grade] ??= $version->availableVoicePartsForGrade($grade)->pluck('id')->all();

                    return in_array($c->voice_part_id, $offeredByGrade[(string) $grade], true);
                },
                'gate' => true,
            ];
        }

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

        if ($version->getRawOriginal('audition_type') === AuditionType::Remote->value) {
            $uploadSlotIds = $version->uploadFiles->pluck('id');

            if ($uploadSlotIds->isNotEmpty()) {
                $mediaLabel = match ($version->getRawOriginal('upload_type')) {
                    UploadType::Audio->value => 'audio',
                    UploadType::Video->value => 'video',
                    default => 'audio/video',
                };

                $items[] = [
                    'label' => "Upload of required {$mediaLabel} files",
                    'check' => fn (Candidate $c): bool => $uploadSlotIds->every(
                        fn (int $slotId): bool => $c->uploadFiles->contains('version_upload_file_id', $slotId),
                    ),
                    // Amber: some but not all of the expected slots have an
                    // upload yet (any status) — a real start, not just unset.
                    'partial' => fn (Candidate $c): bool => $uploadSlotIds->contains(
                        fn (int $slotId): bool => $c->uploadFiles->contains('version_upload_file_id', $slotId),
                    ),
                ];

                $items[] = [
                    'label' => "Teacher approval of required {$mediaLabel} files",
                    'check' => fn (Candidate $c): bool => $uploadSlotIds->every(
                        fn (int $slotId): bool => $c->uploadFiles
                            ->first(fn (CandidateUploadFile $u): bool => $u->version_upload_file_id === $slotId)
                            ?->getRawOriginal('status') === CandidateUploadStatus::Approved->value,
                    ),
                    // Amber: some but not all of the files actually uploaded
                    // so far (not the full slot count) have been approved.
                    'partial' => function (Candidate $c) use ($uploadSlotIds): bool {
                        $uploaded = $c->uploadFiles->whereIn('version_upload_file_id', $uploadSlotIds);

                        if ($uploaded->isEmpty()) {
                            return false;
                        }

                        $approvedCount = $uploaded->filter(
                            fn (CandidateUploadFile $u): bool => $u->getRawOriginal('status') === CandidateUploadStatus::Approved->value,
                        )->count();

                        return $approvedCount > 0 && $approvedCount < $uploaded->count();
                    },
                ];
            }
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
