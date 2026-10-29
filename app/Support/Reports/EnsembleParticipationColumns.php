<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\ShirtSize;
use App\Models\Candidate;
use App\Models\Version;
use Carbon\Carbon;
use Closure;

/**
 * The Ensemble Participation report's optional columns: one per student-
 * data requirement flag enabled on the Version (birthday, height, shirt
 * size, home address, teacher cell, emergency contact email), so the
 * Rehearsal Manager sees whatever profile data the Version collected.
 * Shared by the Livewire page, the CSV export and the PDF so the three
 * can't drift apart. Emergency contact name/phone aren't here — the report
 * always shows those, whatever emergency_contact_name/_cell say.
 *
 * Raw attribute reads (not the casts) for birthday/shirt_size, same as
 * HasCandidateChecklist — legacy rows can hold values the casts reject.
 */
final class EnsembleParticipationColumns
{
    /**
     * @return list<array{label: string, value: Closure(Candidate): string}>
     */
    public static function for(Version $version): array
    {
        $columns = [
            'birthday' => [
                'label' => 'Birthday',
                'value' => function (Candidate $candidate): string {
                    $birthday = $candidate->student->getRawOriginal('birthday');

                    return $birthday ? Carbon::parse((string) $birthday)->format('M j, Y') : '';
                },
            ],
            'height' => [
                'label' => 'Height',
                'value' => fn (Candidate $candidate): string => $candidate->student->height !== null ? $candidate->student->height.'"' : '',
            ],
            'shirt_size' => [
                'label' => 'Shirt Size',
                'value' => fn (Candidate $candidate): string => ShirtSize::tryFrom((string) $candidate->student->getRawOriginal('shirt_size'))?->label() ?? '',
            ],
            'home_address' => [
                'label' => 'Home Address',
                'value' => fn (Candidate $candidate): string => $candidate->student->homeAddress->formatted ?? '',
            ],
            'teacher_cell' => [
                'label' => 'Teacher Cell',
                'value' => fn (Candidate $candidate): string => (string) $candidate->teacher->user->cell_phone,
            ],
            'emergency_contact_email' => [
                'label' => 'Emergency Email',
                'value' => fn (Candidate $candidate): string => (string) $candidate->emergencyContact?->email,
            ],
        ];

        return array_values(array_filter(
            $columns,
            fn (string $flag): bool => (bool) $version->{$flag},
            ARRAY_FILTER_USE_KEY,
        ));
    }
}
