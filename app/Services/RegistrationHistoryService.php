<?php

declare(strict_types=1);

namespace App\Services;

use App\Concerns\HasCandidateChecklist;
use App\Enums\CandidateStatus;
use App\Enums\FeeType;
use App\Enums\ObligationDecision;
use App\Enums\PaymentSource;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\VersionDateType;
use App\Models\Candidate;
use App\Models\CandidateStatusHistory;
use App\Models\LoginEvent;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\StudentTeacher;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Support\RegistrationHistory\HistoryEntry;
use App\Support\RegistrationHistory\RegistrationHistory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Read-only registration history for one student or teacher within one
 * Version, for the Registration History tab on Web Registration.
 *
 * Every $countyIds parameter is the acting user's county scope: null = every
 * county; a list limits to candidates/schools in those counties (a
 * Co-Registration Manager), exactly like VoicePartAuditService.
 *
 * Deliberately never includes scores, contact details, birthdays, waiver
 * reasons or raw payment payloads, and hides audition outcomes until the
 * Version's results are released.
 *
 * Timestamps: candidate_upload_files.uploaded_at and
 * version_invitations.invited_at can be "ON UPDATE CURRENT_TIMESTAMP"
 * columns that move on any later update, so created_at is used instead.
 */
class RegistrationHistoryService
{
    use HasCandidateChecklist;

    private const REGISTRATION_STATUSES = [
        CandidateStatus::Eligible,
        CandidateStatus::Pending,
        CandidateStatus::Registered,
        CandidateStatus::Withdrew,
        CandidateStatus::TeacherWithdrawn,
    ];

    /**
     * @param  list<int>|null  $countyIds
     * @return Builder<Candidate>
     */
    public function candidatesQuery(Version $version, ?array $countyIds): Builder
    {
        return Candidate::query()
            ->where('version_id', $version->id)
            ->when($countyIds !== null, fn (Builder $q) => $q->whereHas('school', fn (Builder $s) => $s->whereIn('county_id', $countyIds ?? [])));
    }

    /**
     * Teachers invited to this Version or with Candidates in it.
     *
     * @param  list<int>|null  $countyIds
     * @return Builder<Teacher>
     */
    public function teachersQuery(Version $version, ?array $countyIds): Builder
    {
        return Teacher::query()->where(function (Builder $q) use ($version, $countyIds): void {
            $q->whereIn('teachers.id', $this->candidatesQuery($version, $countyIds)->select('teacher_id'))
                ->orWhere(function (Builder $invited) use ($version, $countyIds): void {
                    $invited->whereIn('teachers.id', VersionInvitation::query()->where('version_id', $version->id)->select('teacher_id'))
                        ->when($countyIds !== null, fn (Builder $w) => $w->whereHas('schools', fn (Builder $s) => $s
                            ->whereIn('schools.county_id', $countyIds ?? [])
                            ->where('school_teacher.is_active', true)
                            ->whereNotNull('school_teacher.verified_at')));
                });
        });
    }

    /**
     * Every word must appear in the user's name, in any order — so "Balsky
     * Marie" works, and stray double spaces in a stored name don't matter.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function whereNameMatches(Builder $query, string $search): void
    {
        foreach (preg_split('/[\s,]+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $query->where('name', 'like', '%'.addcslashes($word, '%_\\').'%');
        }
    }

    public function forCandidate(Candidate $candidate): RegistrationHistory
    {
        /** @var Version $version */
        $version = $candidate->version;
        $candidates = new EloquentCollection([$candidate]);
        $this->loadCandidates($candidates, $version);

        $user = $candidate->student->user;
        $entries = $this->candidateEntries($candidates, $version, named: false)
            ->concat($this->paymentEntries($this->candidatePayments($candidate), [$candidate->id => $candidate], false))
            ->concat($this->loginEntries($user, $version))
            ->concat($this->deadlineEntries($version));

        $checklist = array_map(
            fn (array $item): array => ['label' => $item['label'], 'done' => ($item['check'])($candidate)],
            $this->checklistDefs($version),
        );

        return new RegistrationHistory(
            kind: 'Student',
            name: $this->name($user),
            versionName: $version->name,
            versionId: $version->id,
            facts: [
                'Candidate' => (string) $candidate->ref,
                'Status' => $this->displayStatus($candidate, $version),
                'Voice part' => $candidate->voicePart->name ?? 'None',
                'School' => $candidate->school->name ?? 'None',
                'Teacher' => $candidate->teacher?->user !== null ? $this->name($candidate->teacher->user) : 'None',
            ],
            checklist: $checklist,
            incomplete: [],
            entries: $this->sort($entries),
            fileKey: $this->fileKey($user, 'student-'.$candidate->ref),
        );
    }

    /**
     * @param  list<int>|null  $countyIds
     */
    public function forTeacher(Version $version, Teacher $teacher, ?array $countyIds): RegistrationHistory
    {
        $candidates = $this->candidatesQuery($version, $countyIds)->where('teacher_id', $teacher->id)->get();
        $this->loadCandidates($candidates, $version);
        $user = $teacher->user;

        $entries = $this->candidateEntries($candidates, $version, named: true)
            ->concat($this->paymentEntries(
                $this->teacherPayments($version, $teacher, $candidates, $countyIds),
                $candidates->keyBy('id')->all(),
                true,
            ))
            ->concat($this->invitationEntries($version, $teacher))
            ->concat($this->schoolEntries($teacher, $countyIds))
            ->concat($this->loginEntries($user, $version))
            ->concat($this->deadlineEntries($version));

        $counts = $candidates
            ->toBase()
            ->countBy(fn (Candidate $c): string => $this->displayStatus($c, $version))
            ->map(fn (int $count, string $label): string => "{$label}: {$count}")
            ->values();

        return new RegistrationHistory(
            kind: 'Teacher',
            name: $this->name($user),
            versionName: $version->name,
            versionId: $version->id,
            facts: [
                'Students' => (string) $candidates->count(),
                'Status' => $counts->implode(', ') ?: 'No students',
            ],
            checklist: [],
            incomplete: $this->incomplete($candidates, $version),
            entries: $this->sort($entries),
            fileKey: $this->fileKey($user, 'teacher-'.$teacher->id),
        );
    }

    /**
     * Cut-offs only ever decide Registered candidates, so Registered is the
     * last status anyone sees here before results are released.
     */
    public function displayStatus(Candidate $candidate, Version $version): string
    {
        $status = $this->status($candidate);

        return in_array($status, self::REGISTRATION_STATUSES, true) || $this->outcomesVisible($version)
            ? $status->label()
            : CandidateStatus::Registered->label();
    }

    /**
     * @param  EloquentCollection<int, Candidate>  $candidates
     */
    private function loadCandidates(EloquentCollection $candidates, Version $version): void
    {
        $version->loadMissing(['dates', 'uploadFiles', 'candidateApplication']);

        $candidates->load([
            'student.user', 'student.schools', 'student.homeAddress', 'student.emergencyContacts',
            'school', 'teacher.user', 'voicePart', 'applicationCertifiedBy',
            'statusHistory.user', 'uploadFiles.versionUploadFile', 'uploadFiles.uploadedBy', 'uploadFiles.decidedBy',
            'feeWaivers.waivedBy',
        ]);
    }

    /**
     * @param  EloquentCollection<int, Candidate>  $candidates
     * @return Collection<int, HistoryEntry>
     */
    private function candidateEntries(EloquentCollection $candidates, Version $version, bool $named): Collection
    {
        /** @var Collection<int, HistoryEntry> $entries */
        $entries = new Collection;

        $links = StudentTeacher::query()
            ->whereIn('student_id', $candidates->pluck('student_id'))
            ->whereIn('teacher_id', $candidates->pluck('teacher_id'))
            ->with('school')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (StudentTeacher $link): string => "{$link->student_id}-{$link->teacher_id}");

        foreach ($candidates as $candidate) {
            $user = $candidate->student->user;
            $student = $named ? $this->studentLabel($candidate) : null;
            $add = function (?CarbonInterface $at, string $text, ?User $by = null) use ($entries, $student): void {
                if ($at !== null) {
                    $entries->push(new HistoryEntry($at, $text, $by !== null ? $this->name($by) : null, $student));
                }
            };

            $add($user->created_at, 'Student account created');

            foreach ($links->get("{$candidate->student_id}-{$candidate->teacher_id}") ?? [] as $link) {
                $add($link->created_at, 'Added to teacher\'s roster at '.($link->school->name ?? 'unknown school'));
            }

            $this->addStatusEntries($candidate, $version, $add);

            foreach ($candidate->uploadFiles as $file) {
                $slot = $file->versionUploadFile->name ?? 'recording';
                $original = $file->original_filename !== null ? " ({$file->original_filename})" : '';
                $add($file->created_at, "Recording uploaded: {$slot}{$original}", $file->uploadedBy);
                $add($this->at($file, 'decided_at'), "Recording approved: {$slot}", $file->decidedBy);
            }

            $add($this->at($candidate, 'application_candidate_signed_at'), 'Candidate signature recorded');
            $add($this->at($candidate, 'application_parent_signed_at'), 'Parent signature recorded');
            $add($this->at($candidate, 'application_certified_at'), 'Application signatures certified', $candidate->applicationCertifiedBy);

            foreach ($candidate->feeWaivers as $waiver) {
                $fee = FeeType::tryFrom((string) $waiver->getRawOriginal('fee_type'))?->label() ?? 'Fee';
                $add($waiver->created_at, "Fee waived: {$fee}", $waiver->waivedBy);
            }
        }

        return $entries;
    }

    /**
     * Registration moves are listed as they happened. Cut-off trials write
     * back-and-forth outcome rows, so those collapse to the final outcome,
     * and only once results are released.
     *
     * @param  \Closure(?CarbonInterface, string, ?User=): void  $add
     */
    private function addStatusEntries(Candidate $candidate, Version $version, \Closure $add): void
    {
        $history = $candidate->statusHistory
            ->sortBy('id')
            ->sortBy(fn (CandidateStatusHistory $row): int => (int) $this->at($row, 'created_at')?->getTimestamp())
            ->values();

        if (! $history->contains(fn (CandidateStatusHistory $row): bool => $row->getRawOriginal('from_status') === null)) {
            $add($candidate->created_at, 'Candidate record created');
        }

        $outcome = null;

        foreach ($history as $row) {
            $from = CandidateStatus::tryFrom((string) $row->getRawOriginal('from_status'));
            $to = CandidateStatus::tryFrom((string) $row->getRawOriginal('to_status'));

            if ($to === null) {
                continue;
            }

            if (! in_array($to, self::REGISTRATION_STATUSES, true)) {
                $outcome = $row;

                continue;
            }

            if ($from !== null && ! in_array($from, self::REGISTRATION_STATUSES, true)) {
                continue;
            }

            $add(
                $this->at($row, 'created_at'),
                $from === null ? "Candidate created ({$to->label()})" : "Status changed: {$from->label()} → {$to->label()}",
                $row->user,
            );
        }

        $current = $this->status($candidate);

        if ($outcome !== null && $this->outcomesVisible($version) && ! in_array($current, self::REGISTRATION_STATUSES, true)) {
            $add($this->at($outcome, 'created_at'), "Outcome: {$current->label()}", $outcome->user);
        }
    }

    private function status(Candidate $candidate): CandidateStatus
    {
        return CandidateStatus::from((string) $candidate->getRawOriginal('status'));
    }

    private function outcomesVisible(Version $version): bool
    {
        return $this->at($version, 'results_released_at')?->isFuture() === false;
    }

    /**
     * @param  EloquentCollection<int, Candidate>  $candidates
     * @return list<array{student: string, status: string, missing: list<string>}>
     */
    private function incomplete(EloquentCollection $candidates, Version $version): array
    {
        $defs = $this->checklistDefs($version);
        $rows = [];

        foreach ($candidates->sortBy(fn (Candidate $c): string => $c->student->user->sort_name) as $candidate) {
            $status = $this->status($candidate);

            if (! in_array($status, [CandidateStatus::Eligible, CandidateStatus::Pending], true)) {
                continue;
            }

            $missing = [];

            foreach ($defs as $item) {
                if (! ($item['check'])($candidate)) {
                    $missing[] = $item['label'];
                }
            }

            $rows[] = [
                'student' => $this->studentLabel($candidate),
                'status' => $status->label(),
                'missing' => $missing,
            ];
        }

        return $rows;
    }

    /**
     * @return EloquentCollection<int, PaymentTransaction>
     */
    private function candidatePayments(Candidate $candidate): EloquentCollection
    {
        return PaymentTransaction::query()
            ->where('version_id', $candidate->version_id)
            ->where(fn (Builder $q) => $q
                ->whereHas('allocations', fn (Builder $a) => $a->where('candidate_id', $candidate->id))
                ->orWhere(fn (Builder $p) => $p->where('payer_student_id', $candidate->student_id)->whereDoesntHave('allocations')))
            ->with(['allocations.allocatedBy', 'recordedBy'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Candidate>  $candidates
     * @param  list<int>|null  $countyIds
     * @return EloquentCollection<int, PaymentTransaction>
     */
    private function teacherPayments(Version $version, Teacher $teacher, EloquentCollection $candidates, ?array $countyIds): EloquentCollection
    {
        return PaymentTransaction::query()
            ->where('version_id', $version->id)
            ->where(fn (Builder $q) => $q
                ->whereHas('allocations', fn (Builder $a) => $a->whereIn('candidate_id', $candidates->modelKeys()))
                ->orWhere(fn (Builder $p) => $p
                    ->where('payer_teacher_id', $teacher->id)
                    // County-scoped: only money not tied to another county's
                    // candidate, recorded for an in-county school.
                    ->when($countyIds !== null, fn (Builder $w) => $w
                        ->whereDoesntHave('allocations')
                        ->whereHas('school', fn (Builder $s) => $s->whereIn('county_id', $countyIds ?? [])))))
            ->with(['allocations.allocatedBy', 'recordedBy'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, PaymentTransaction>  $payments
     * @param  array<int, Candidate>  $candidates  those in view, by id
     * @return Collection<int, HistoryEntry>
     */
    private function paymentEntries(EloquentCollection $payments, array $candidates, bool $named): Collection
    {
        /** @var Collection<int, HistoryEntry> $entries */
        $entries = new Collection;

        foreach ($payments as $payment) {
            $source = $payment->getRawOriginal('source');
            $manual = $source === PaymentSource::Manual->value;
            $group = $source === PaymentSource::TeacherEpayment->value;
            $status = PaymentTransactionStatus::tryFrom((string) $payment->getRawOriginal('status'));
            $visible = $payment->allocations->filter(fn (PaymentAllocation $a): bool => isset($candidates[$a->candidate_id]));
            $single = ! $group && $visible->count() === 1 ? $visible->first() : null;

            // A shared payment's total can include candidates outside this
            // view, so show only the visible share of it unless nothing is
            // hidden.
            $cents = ($named || $visible->isEmpty()) && $visible->count() === $payment->allocations->count()
                ? $payment->amount
                : (int) $visible->sum('amount');
            $fee = FeeType::tryFrom((string) $payment->getRawOriginal('fee_type'))?->label() ?? 'Fee';
            $amount = $this->money($cents);
            $student = $named && $single !== null ? $this->studentLabel($candidates[$single->candidate_id]) : null;
            $paidAt = $this->at($payment, 'paid_at');
            $actor = $payment->recordedBy !== null ? $this->name($payment->recordedBy) : null;

            // An unscoped teacher's payment allocated only to someone else's
            // students now (e.g. after a transfer): still their payment.
            if ($named && $visible->isEmpty()) {
                $amount = $this->money($payment->amount);
            }

            if ($manual) {
                $type = PaymentType::tryFrom((string) $payment->getRawOriginal('payment_type'))?->label() ?? 'payment';
                // Manual paid_at is a date with no time: never time-zone shift it.
                $dated = $paidAt !== null ? ', dated '.$paidAt->format('M j, Y') : '';
                $text = "Payment recorded: {$fee}, {$amount} {$type}{$dated}";
            } else {
                $text = ($group ? 'Group e-payment' : 'E-payment')." checkout started: {$fee}, {$amount}";
            }

            if ($status !== null && ! ($manual && $status === PaymentTransactionStatus::Completed)) {
                $text .= " (now {$status->label()})";
            }

            if ($payment->created_at !== null) {
                $entries->push(new HistoryEntry($payment->created_at, $text, $actor, $student));
            }

            if (! $manual && $paidAt !== null) {
                $entries->push(new HistoryEntry($paidAt, "E-payment confirmed: {$fee}, {$amount}", null, $student));
            }

            if ($single !== null) {
                continue;
            }

            foreach ($visible as $allocation) {
                $allocatedAt = $this->at($allocation, 'allocated_at');

                if ($allocatedAt === null) {
                    continue;
                }

                $entries->push(new HistoryEntry(
                    $allocatedAt,
                    "Payment applied: {$fee}, {$this->money($allocation->amount)}",
                    $allocation->allocatedBy !== null ? $this->name($allocation->allocatedBy) : null,
                    $named ? $this->studentLabel($candidates[$allocation->candidate_id]) : null,
                ));
            }
        }

        return $entries;
    }

    /**
     * @return Collection<int, HistoryEntry>
     */
    private function invitationEntries(Version $version, Teacher $teacher): Collection
    {
        /** @var Collection<int, HistoryEntry> $entries */
        $entries = new Collection;
        $invitation = VersionInvitation::query()
            ->where('version_id', $version->id)
            ->where('teacher_id', $teacher->id)
            ->with(['invitedBy', 'obligationResponse'])
            ->first();

        if ($invitation === null) {
            return $entries;
        }

        if ($invitation->created_at !== null) {
            $entries->push(new HistoryEntry(
                $invitation->created_at,
                'Teacher invited',
                $invitation->invitedBy !== null ? $this->name($invitation->invitedBy) : null,
            ));
        }

        $response = $invitation->obligationResponse;

        $decidedAt = $response !== null ? $this->at($response, 'decided_at') : null;

        if ($response === null || $decidedAt === null) {
            return $entries;
        }

        if ($response->created_at !== null && ! $response->created_at->equalTo($decidedAt)) {
            $entries->push(new HistoryEntry($response->created_at, 'Obligations first answered (that decision is not kept)'));
        }

        $decision = ObligationDecision::tryFrom((string) $response->getRawOriginal('decision'))?->label() ?? 'answered';
        $entries->push(new HistoryEntry($decidedAt, "Obligations: {$decision} (latest decision)"));

        return $entries;
    }

    /**
     * @param  list<int>|null  $countyIds
     * @return Collection<int, HistoryEntry>
     */
    private function schoolEntries(Teacher $teacher, ?array $countyIds): Collection
    {
        /** @var Collection<int, HistoryEntry> $entries */
        $entries = new Collection;

        $links = SchoolTeacher::query()
            ->where('teacher_id', $teacher->id)
            ->when($countyIds !== null, fn (Builder $q) => $q->whereHas('school', fn (Builder $s) => $s->whereIn('county_id', $countyIds ?? [])))
            ->with('school')
            ->orderBy('id')
            ->get();

        foreach ($links as $link) {
            $school = $link->school->name ?? 'unknown school';

            if ($link->created_at !== null) {
                $entries->push(new HistoryEntry($link->created_at, "Teacher added school: {$school}"));
            }

            $verifiedAt = $this->at($link, 'verified_at');

            if ($verifiedAt !== null) {
                $entries->push(new HistoryEntry($verifiedAt, "Teacher verified at {$school}"));
            }
        }

        return $entries;
    }

    /**
     * Logins aren't tied to a Version, so only those from its admin-open date
     * onward are listed.
     *
     * @return Collection<int, HistoryEntry>
     */
    private function loginEntries(User $user, Version $version): Collection
    {
        $from = $version->dates->firstWhere('date_type', VersionDateType::Admin)->start_at ?? $version->created_at;

        return LoginEvent::query()
            ->where('user_id', $user->id)
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->orderBy('id')
            ->get()
            ->toBase()
            ->map(fn (LoginEvent $login): HistoryEntry => new HistoryEntry(
                $login->created_at,
                'Logged in ('.str_replace('_', ' ', (string) $login->getRawOriginal('method')).')',
                $this->name($user),
            ));
    }

    /**
     * @return Collection<int, HistoryEntry>
     */
    private function deadlineEntries(Version $version): Collection
    {
        /** @var Collection<int, HistoryEntry> $entries */
        $entries = new Collection;
        $shown = [
            VersionDateType::Teacher, VersionDateType::Candidate, VersionDateType::FinalTeacherChanges,
            VersionDateType::PostmarkDeadline, VersionDateType::Adjudication,
        ];
        $add = function (?CarbonInterface $at, string $text) use ($entries): void {
            if ($at !== null && ! $at->isFuture()) {
                $entries->push(new HistoryEntry($at, $text, isDeadline: true));
            }
        };

        foreach ($version->dates as $date) {
            $type = VersionDateType::tryFrom((string) $date->getRawOriginal('date_type'));

            if ($type === null || ! in_array($type, $shown, true)) {
                continue;
            }

            $isDeadline = in_array($type, [VersionDateType::FinalTeacherChanges, VersionDateType::PostmarkDeadline], true);
            // "Postmark Deadline" already says it.
            $label = Str::endsWith($type->label(), 'Deadline') ? $type->label() : "{$type->label()} deadline";
            $add($this->at($date, 'start_at'), $isDeadline ? $label : "{$type->label()} opened");
            $add($this->at($date, 'end_at'), "{$type->label()} closed");
        }

        $add($this->at($version, 'results_released_at'), 'Results released');

        return $entries;
    }

    /**
     * Oldest first; sortBy is stable, so ties keep the order they were added.
     *
     * @param  Collection<int, HistoryEntry>  $entries
     * @return Collection<int, HistoryEntry>
     */
    private function sort(Collection $entries): Collection
    {
        return $entries->sortBy(fn (HistoryEntry $entry): int => $entry->at->getTimestamp())->values();
    }

    /**
     * A cast datetime attribute. Larastan reads method-based datetime casts as
     * plain strings (PHPStan-quirks memory), so narrow it at runtime instead.
     */
    private function at(Model $model, string $attribute): ?CarbonInterface
    {
        $value = $model->getAttribute($attribute);

        return $value instanceof CarbonInterface ? $value : null;
    }

    private function studentLabel(Candidate $candidate): string
    {
        return "{$this->name($candidate->student->user)} ({$candidate->ref})";
    }

    private function name(User $user): string
    {
        return Str::squish($user->name);
    }

    private function fileKey(User $user, string $fallback): string
    {
        return Str::slug("{$user->last_name} {$user->first_name}") ?: Str::slug($fallback);
    }

    private function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
