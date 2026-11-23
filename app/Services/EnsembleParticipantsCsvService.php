<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\ScoreOrder;
use App\Models\AuditionResult;
use App\Models\Candidate;
use App\Models\EmergencyContact;
use App\Models\Ensemble;
use App\Models\Phone;
use App\Models\Pivots\SchoolStudent;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEnsembleParticipantExport;
use App\Support\ClassOfCalculator;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The Ensemble Participants CSVs: one file per Ensemble listing its accepted
 * candidates with school, teacher, score and contact details, in the layout
 * Event Managers used in earlier years (e.g. mx_ensembleParticipants_*.csv).
 * Built at Close Audition (GenerateEnsembleParticipantsCsvJob) and on demand
 * from Reports → Downloads, stored on S3, one current file per Version ×
 * Ensemble (version_ensemble_participant_exports).
 *
 * Only candidates whose status is Accepted into the Ensemble are included,
 * so a student who declines drops out the next time the files are built.
 * Student home and teacher work phones are left out: the app only collects
 * cell numbers. Up to three emergency contacts: the one chosen on the candidate's
 * application first, then the student's others in the order they were added.
 */
final class EnsembleParticipantsCsvService
{
    public const HEADER = [
        'name', 'lastName', 'class of', 'grade', 'school', 'teacher', 'vp', 'vp-sort', 'score',
        'student email', 'student cell',
        'teacher email', 'teacher cell',
        'emergency contact', 'ec email1', 'ec cell1', 'ec home1', 'ec work1',
        'ec name2', 'ec email2', 'ec cell2', 'ec home2', 'ec work2',
        'ec name3', 'ec email3', 'ec cell3', 'ec home3', 'ec work3',
    ];

    private const EMERGENCY_CONTACTS = 3;

    /**
     * Builds and stores every Ensemble's file for the Version, replacing
     * any earlier ones.
     *
     * @return list<VersionEnsembleParticipantExport>
     */
    public function generateAll(Version $version, ?User $generatedBy = null, ?Carbon $at = null): array
    {
        $at ??= Carbon::now();
        $exports = [];

        foreach ($version->ensembleOrder()->with('ensemble')->get() as $order) {
            $exports[] = $this->generate($version, $order->ensemble, $generatedBy, $at);
        }

        return $exports;
    }

    public function generate(Version $version, Ensemble $ensemble, ?User $generatedBy = null, ?Carbon $at = null): VersionEnsembleParticipantExport
    {
        $at ??= Carbon::now();
        $rows = $this->rows($version, $ensemble);

        $prefix = $ensemble->abbreviation !== null && trim($ensemble->abbreviation) !== '' ? trim($ensemble->abbreviation) : 'ensemble'.$ensemble->id;
        $filename = str($prefix)->slug('_')->value().'_ensembleParticipants_'.$at->format('Ymd_His').'.csv';
        $key = "versions/{$version->id}/ensemble-participants/{$filename}";

        // Not fire-and-forget: the s3 disk doesn't throw on failure, so a
        // false here must stop the row from pointing at a missing file.
        if (Storage::disk('s3')->put($key, $this->csv($rows)) === false) {
            throw new RuntimeException("Couldn't store {$key}.");
        }

        $existing = VersionEnsembleParticipantExport::where('version_id', $version->id)->where('ensemble_id', $ensemble->id)->first();
        $oldKey = $existing?->s3_key;

        $export = VersionEnsembleParticipantExport::updateOrCreate(
            ['version_id' => $version->id, 'ensemble_id' => $ensemble->id],
            [
                's3_key' => $key,
                'filename' => $filename,
                'row_count' => count($rows),
                'generated_at' => $at,
                'generated_by_user_id' => $generatedBy?->id,
            ],
        );

        if ($oldKey !== null && $oldKey !== $key) {
            Storage::disk('s3')->delete($oldKey);
        }

        return $export;
    }

    /**
     * The file's data rows (no header), keyed by HEADER, best score first
     * within each Voice Part.
     *
     * @return list<array<string, string>>
     */
    public function rows(Version $version, Ensemble $ensemble): array
    {
        $candidates = Candidate::where('version_id', $version->id)
            ->where('status', CandidateStatus::Accepted->value)
            ->where('accepted_ensemble_id', $ensemble->id)
            ->with([
                'student.user.phones',
                'student.emergencyContacts',
                'school',
                'teacher.user.phones',
                'voicePart',
            ])
            ->get();

        $totals = AuditionResult::whereIn('candidate_id', $candidates->pluck('id'))->pluck('total', 'candidate_id');

        // Each student's class of at the candidate's school: "studentId:schoolId" => class_of.
        $classOf = SchoolStudent::whereIn('student_id', $candidates->pluck('student_id'))
            ->get(['student_id', 'school_id', 'class_of'])
            ->mapWithKeys(fn (SchoolStudent $pivot): array => ["{$pivot->student_id}:{$pivot->school_id}" => (int) $pivot->class_of]);
        $higherIsBetter = $version->getRawOriginal('score_order') === ScoreOrder::Desc->value;

        return $candidates
            ->sort(function (Candidate $a, Candidate $b) use ($totals, $higherIsBetter): int {
                $voicePart = ($a->voicePart->sort_order ?? PHP_INT_MAX) <=> ($b->voicePart->sort_order ?? PHP_INT_MAX);
                if ($voicePart !== 0) {
                    return $voicePart;
                }

                $scoreA = (int) ($totals[$a->id] ?? 0);
                $scoreB = (int) ($totals[$b->id] ?? 0);
                $score = $higherIsBetter ? $scoreB <=> $scoreA : $scoreA <=> $scoreB;
                if ($score !== 0) {
                    return $score;
                }

                return [mb_strtolower((string) $a->student->user->last_name), mb_strtolower((string) $a->student->user->first_name)]
                    <=> [mb_strtolower((string) $b->student->user->last_name), mb_strtolower((string) $b->student->user->first_name)];
            })
            ->map(fn (Candidate $candidate): array => $this->row($candidate, $totals[$candidate->id] ?? null, $classOf->get("{$candidate->student_id}:{$candidate->school_id}")))
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function row(Candidate $candidate, mixed $total, ?int $classOf): array
    {
        $student = $candidate->student->user;
        $teacher = $candidate->teacher?->user;

        $row = [
            'name' => (string) $student->name,
            'lastName' => (string) $student->last_name,
            'class of' => $classOf === null ? '' : (string) $classOf,
            'grade' => $classOf === null || $candidate->school === null ? '' : (string) ClassOfCalculator::gradeFromClassOf($classOf, (int) $candidate->school->senior_year),
            'school' => (string) ($candidate->school->name ?? ''),
            'teacher' => (string) ($teacher->name ?? ''),
            'vp' => (string) ($candidate->voicePart->abbr ?? ''),
            'vp-sort' => (string) ($candidate->voicePart->sort_order ?? ''),
            'score' => $total === null ? '' : (string) (int) $total,
            'student email' => (string) $student->email,
            'student cell' => $this->userPhone($student, 'cell'),
            'teacher email' => (string) ($teacher->email ?? ''),
            'teacher cell' => $teacher === null ? '' : $this->userPhone($teacher, 'cell'),
        ];

        $contacts = $this->emergencyContacts($candidate);
        for ($i = 0; $i < self::EMERGENCY_CONTACTS; $i++) {
            $contact = $contacts[$i] ?? null;
            $n = $i + 1;
            $row[$n === 1 ? 'emergency contact' : "ec name{$n}"] = (string) ($contact->name ?? '');
            $row["ec email{$n}"] = (string) ($contact->email ?? '');
            $row["ec cell{$n}"] = $this->format($contact?->cell_phone);
            $row["ec home{$n}"] = $this->format($contact?->home_phone);
            $row["ec work{$n}"] = $this->format($contact?->work_phone);
        }

        return $row;
    }

    /**
     * The contact chosen on the application first, then the student's
     * other contacts in the order they were added; at most three.
     *
     * @return list<EmergencyContact>
     */
    private function emergencyContacts(Candidate $candidate): array
    {
        return $candidate->student->emergencyContacts
            ->sortBy(fn (EmergencyContact $contact): array => [$contact->id === $candidate->emergency_contact_id ? 0 : 1, $contact->id])
            ->take(self::EMERGENCY_CONTACTS)
            ->values()
            ->all();
    }

    private function userPhone(User $user, string $type): string
    {
        $phone = $user->phones->first(fn (Phone $phone): bool => $phone->getRawOriginal('type') === $type);

        if ($phone !== null && $phone->formatted !== '') {
            return $phone->formatted;
        }

        return $type === 'cell' ? $this->format($user->cell_phone) : '';
    }

    private function format(?string $number): string
    {
        return PhoneNormalizer::format($number) ?? '';
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Couldn\'t open a temporary stream for the CSV.');
        }

        fputcsv($handle, self::HEADER, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column): string => $row[$column] ?? '', self::HEADER), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The current exports for a Version, keyed by ensemble id.
     *
     * @return Collection<int, VersionEnsembleParticipantExport>
     */
    public function exportsFor(Version $version): Collection
    {
        return VersionEnsembleParticipantExport::where('version_id', $version->id)->get()->keyBy('ensemble_id');
    }
}
