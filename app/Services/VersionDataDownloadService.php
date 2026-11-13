<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BestPhone;
use App\Enums\CandidateStatus;
use App\Enums\CandidateUploadStatus;
use App\Enums\EmergencyContactRelationship;
use App\Enums\FeeType;
use App\Enums\JudgeType;
use App\Enums\ObligationDecision;
use App\Enums\PaymentTransactionStatus;
use App\Enums\SchoolType;
use App\Enums\ShirtSize;
use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Enums\VersionInvitationStatus;
use App\Models\Candidate;
use App\Models\CandidateUploadFile;
use App\Models\EmergencyContact;
use App\Models\Ensemble;
use App\Models\Membership;
use App\Models\PaymentAllocation;
use App\Models\Phone;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\SchoolTeacher;
use App\Models\Pivots\SchoolTeacherSubject;
use App\Models\Pivots\StudentTeacher;
use App\Models\Recording;
use App\Models\School;
use App\Models\SchoolGrade;
use App\Models\Score;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionInvitation;
use App\Models\VoicePart;
use App\Support\ClassOfCalculator;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Row data for the Reports → Downloads card: five CSV exports of the data a
 * Version "owns", for the Event Manager to keep. Every export translates
 * foreign keys to their human-readable values and omits database-only
 * columns (ids, created_at/updated_at) and credentials (password,
 * two-factor secrets, remember token). The candidate `ref` is kept — it is a
 * human-facing reference, not a database id.
 *
 * "Owned" (product owner, 2026-11-13): every teacher with a VersionInvitation
 * row for this Version (any status — invited, obligated, rejected,
 * participating), their active schools, and those teachers' students at an
 * active (school_student.is_active) school. Not county-scoped — the card is
 * Event Manager only.
 *
 * Every record method returns rows that all share the same keys in the same
 * order, so the first row doubles as the CSV header.
 */
class VersionDataDownloadService
{
    public const TOPICS = ['event-version', 'schools', 'teachers', 'students', 'candidates'];

    public function __construct(
        private readonly CandidateFeeCalculator $fees,
        private readonly EnsembleCutoffService $cutoffs,
    ) {}

    /**
     * Two-column key/value pairs for the Event, the Version, and the
     * Version's related settings, each setting under its own dotted key.
     *
     * @return list<array{key: string, value: string}>
     */
    public function eventVersionPairs(Version $version): array
    {
        $version->loadMissing([
            'event.organization',
            'event.grades',
            'event.ensembles.grades',
            'event.ensembles.voiceParts',
            'fees',
            'dates',
            'counties.county',
            'classOfs',
            'ensembleOrder',
            'timeslots.school',
            'rooms.roomJudges.user',
            'rooms.scoreCategories',
            'rooms.voiceParts',
            'membershipRequirement',
            'obligation',
        ]);

        $event = $version->event;
        $pairs = [];
        $add = function (string $key, mixed $value) use (&$pairs): void {
            $pairs[] = ['key' => $key, 'value' => $this->display($value)];
        };

        $add('event.name', $event->name);
        $add('event.short_name', $event->short_name);
        $add('event.organization', $event->organization?->name);
        $add('event.status', $event->status);
        $add('event.frequency', $event->frequency);
        $add('event.audition_count', $event->audition_count);
        $add('event.ensemble_count', $event->ensemble_count);
        $add('event.logo_url', $event->logo_url);
        $add('event.logo_alt', $event->logo_alt);
        $add('event.grades', $event->grades->sortBy('grade')->pluck('grade')->implode(', '));

        foreach ([
            'name', 'short_name', 'senior_class_of', 'status', 'application_type', 'audition_type',
            'audition_timeslot', 'judge_count', 'max_registrants', 'max_upper_voice_registrants',
            'audition_cap_per_school', 'pitch_file_visibility', 'score_order', 'cutoff_strategy',
            'upload_type', 'share_results', 'mail_required', 'birthday', 'height', 'shirt_size',
            'home_address', 'emergency_contact_name', 'emergency_contact_cell', 'emergency_contact_email',
            'teacher_cell',
        ] as $field) {
            $add("version.{$field}", $version->{$field});
        }
        $add('version.results_released_at', $this->dateTime($version->results_released_at));

        $add('version.class_of', $version->classOfs->sortBy('class_of')->pluck('class_of')->implode(', '));

        foreach ($version->dates->sortBy(fn ($date): string => (string) $date->getRawOriginal('date_type')) as $date) {
            $type = $date->getRawOriginal('date_type');
            $add("date.{$type}.start", $this->dateTime($date->start_at));
            $add("date.{$type}.end", $this->dateTime($date->end_at));
        }

        foreach (['registration', 'on_site_registration', 'participation', 'housing', 'epayment_surcharge'] as $fee) {
            $add("fee.{$fee}", $this->dollars($version->fees?->{$fee} === null ? null : (int) $version->fees->{$fee}));
        }

        foreach ($version->counties->sortBy(fn ($vc): string => (string) $vc->county?->name)->values() as $i => $versionCounty) {
            $add('county.'.($i + 1), $versionCounty->county?->name);
        }

        $orderByEnsembleId = $version->ensembleOrder->pluck('order_by', 'ensemble_id');
        $ensembles = $event->ensembles
            ->sortBy(fn (Ensemble $e): array => [$orderByEnsembleId->get($e->id, PHP_INT_MAX), $e->name])
            ->values();

        foreach ($ensembles as $i => $ensemble) {
            $prefix = 'ensemble.'.($i + 1);
            $add("{$prefix}.name", $ensemble->name);
            $add("{$prefix}.short_name", $ensemble->short_name);
            $add("{$prefix}.abbreviation", $ensemble->abbreviation);
            $add("{$prefix}.color", $ensemble->color);
            $add("{$prefix}.grades", $ensemble->grades->sortBy('grade')->pluck('grade')->implode(', '));
            $add("{$prefix}.voice_parts", $ensemble->voiceParts->sortBy('sort_order')->pluck('name')->implode(', '));
            $add("{$prefix}.order", $orderByEnsembleId->get($ensemble->id));
        }

        $obligation = $version->obligation;
        $add('obligation.title', $obligation?->title);
        $add('obligation.body', $obligation?->body);
        $add('obligation.status', $obligation?->status);
        $add('obligation.published_at', $this->dateTime($obligation?->published_at));

        foreach ($version->timeslots->sortBy(fn ($slot): string => (string) $slot->school?->name)->values() as $i => $slot) {
            $prefix = 'timeslot.'.($i + 1);
            $add("{$prefix}.school", $slot->school?->name);
            $add("{$prefix}.timeslot", $this->dateTime($slot->timeslot));
        }

        foreach ($version->rooms->sortBy('order_by')->values() as $i => $room) {
            $prefix = 'room.'.($i + 1);
            $add("{$prefix}.name", $room->name);
            $add("{$prefix}.tolerance", $room->tolerance);
            $add("{$prefix}.score_categories", $room->scoreCategories->sortBy('order_by')->pluck('description')->implode(', '));
            $add("{$prefix}.voice_parts", $room->voiceParts->sortBy('sort_order')->pluck('name')->implode(', '));

            foreach ($room->roomJudges->sortBy(fn ($judge): string => (string) $judge->getRawOriginal('judge_type')) as $judge) {
                $add("{$prefix}.".$judge->getRawOriginal('judge_type'), $judge->user?->name);
            }
        }

        $requirement = $version->membershipRequirement;
        $add('membership_requirement.membership_card', $requirement?->membership_card);
        $add('membership_requirement.valid_thru', $this->date($requirement?->valid_thru));

        return $pairs;
    }

    /**
     * One row per owned school.
     *
     * @return list<array<string, string>>
     */
    public function schoolRows(Version $version): array
    {
        $teacherSchools = $this->teacherSchools($version);
        $schoolIds = $teacherSchools->pluck('school_id')
            ->merge(Candidate::where('version_id', $version->id)->pluck('school_id'))
            ->filter()
            ->unique();

        $schools = School::whereIn('id', $schoolIds)->with(['county', 'geostate'])->orderBy('name')->get();
        $gradesBySchool = SchoolGrade::whereIn('school_id', $schoolIds)->orderBy('grade')->get()->groupBy('school_id');
        $teachersBySchool = $teacherSchools->groupBy('school_id');

        return $schools->map(fn (School $school): array => [
            'name' => $school->name,
            'type' => $this->rawEnum($school, 'type', SchoolType::class),
            'city' => $this->display($school->city),
            'county' => $this->display($school->county?->name),
            'state' => $this->display($school->geostate?->abbr),
            'zip_code' => $this->display($school->zip_code),
            'school_year' => $this->display($school->school_year),
            'grades' => ($gradesBySchool->get($school->id) ?? collect())->pluck('grade')->implode(', '),
            'teachers' => ($teachersBySchool->get($school->id) ?? collect())
                ->map(fn (SchoolTeacher $row): string => $row->teacher->user->name)
                ->sort()
                ->implode('; '),
        ])->values()->all();
    }

    /**
     * One row per owned teacher at each of their active schools — a teacher
     * at two schools appears twice.
     *
     * @return list<array<string, string>>
     */
    public function teacherRows(Version $version): array
    {
        $invitations = $this->invitations($version)->keyBy('teacher_id');
        $rows = $this->teacherSchools($version);

        $memberships = Membership::whereIn('teacher_id', $rows->pluck('teacher_id')->unique())
            ->where('organization_id', $version->event->organization_id)
            ->get()
            ->keyBy('teacher_id');

        return $rows->map(function (SchoolTeacher $row) use ($invitations, $memberships): array {
            $teacher = $row->teacher;
            $school = $row->school;
            $invitation = $invitations->get($row->teacher_id);
            $membership = $memberships->get($row->teacher_id);
            $card = $membership?->membership_card;

            return [
                ...$this->userFields($teacher->user),
                'school.name' => $school->name,
                'school.city' => $this->display($school->city),
                'school.county' => $this->display($school->county?->name),
                'school.role' => $this->rawEnum($row, 'role', TeacherRole::class),
                'school.subjects' => $row->subjects
                    ->map(fn (SchoolTeacherSubject $subject): string => $this->rawEnum($subject, 'subject', Subject::class))
                    ->sort()
                    ->implode(', '),
                'school.school_email' => $this->display($row->school_email),
                'school.is_active' => $this->display((bool) $row->is_active),
                'school.verified_at' => $this->dateTime($row->verified_at),
                'school.replacing_teacher_name' => $this->display($row->replacing_teacher_name),
                'school.replaced_teacher' => $this->display($row->replacedTeacher?->user->name),
                'school.replacement_transferred_at' => $this->dateTime($row->replacement_transferred_at),
                'onboarding_completed_at' => $this->dateTime($teacher->onboarding_completed_at),
                'membership.number' => $this->display($membership?->membership_number),
                'membership.expires_at' => $this->date($membership?->membership_expires_at),
                'membership.card_file' => $card === null || $card === '' ? '' : basename($card),
                'invitation.status' => $this->rawEnum($invitation, 'status', VersionInvitationStatus::class),
                'invitation.invited_at' => $this->dateTime($invitation?->invited_at),
                'obligation.decision' => $this->rawEnum($invitation?->obligationResponse, 'decision', ObligationDecision::class),
                'obligation.decided_at' => $this->dateTime($invitation?->obligationResponse?->decided_at),
            ];
        })->values()->all();
    }

    /**
     * One row per owned student — a student of an invited teacher at that
     * student's active school (school_student.is_active) — with guardians as
     * numbered columns (as many as the student with the most has).
     *
     * @return list<array<string, string>>
     */
    public function studentRows(Version $version): array
    {
        $teacherIds = $this->invitations($version)->pluck('teacher_id')->unique();

        // Every active student↔teacher link for those students, for any
        // teacher, so the "teachers" column lists them all.
        $links = StudentTeacher::where('is_active', true)
            ->whereIn('student_id', StudentTeacher::whereIn('teacher_id', $teacherIds)->where('is_active', true)->select('student_id'))
            ->with('teacher.user')
            ->get();

        $ownedPairs = $links->filter(fn (StudentTeacher $link): bool => $teacherIds->contains($link->teacher_id))
            ->map(fn (StudentTeacher $link): string => "{$link->student_id}:{$link->school_id}")
            ->unique()
            ->flip();

        $enrollments = SchoolStudent::where('is_active', true)
            ->whereIn('student_id', $links->pluck('student_id')->unique())
            ->with([
                'school.county',
                'student.user.pronoun',
                'student.user.phones',
                'student.homeSchool',
                'student.homeAddress',
                'student.emergencyContacts',
                'student.voicePart',
                'student.instrument',
            ])
            ->get()
            ->filter(fn (SchoolStudent $enrollment): bool => $ownedPairs->has("{$enrollment->student_id}:{$enrollment->school_id}"))
            ->unique('student_id')
            ->sortBy(fn (SchoolStudent $e): string => mb_strtolower($e->student->user->last_name.' '.$e->student->user->first_name))
            ->values();

        $teacherNames = $links->groupBy(fn (StudentTeacher $link): string => "{$link->student_id}:{$link->school_id}")
            ->map(fn (Collection $group): string => $group->map(fn (StudentTeacher $link): string => $link->teacher->user->name)->unique()->sort()->implode('; '));

        $guardianCount = (int) $enrollments->max(fn (SchoolStudent $e): int => $e->student->emergencyContacts->count());

        return $enrollments->map(function (SchoolStudent $enrollment) use ($guardianCount, $teacherNames): array {
            $student = $enrollment->student;
            $school = $enrollment->school;
            $address = $student->homeAddress;
            $classOf = (int) $enrollment->class_of;

            $row = [
                ...$this->userFields($student->user),
                'birthday' => $this->date($student->birthday),
                'height' => $this->display($student->height),
                'shirt_size' => $this->rawEnum($student, 'shirt_size', ShirtSize::class),
                'voice_part' => $this->display($student->voicePart?->name),
                'instrument' => $this->display($student->instrument?->name),
                'school' => $school->name,
                'school.county' => $this->display($school->county?->name),
                'class_of' => $this->display($classOf),
                'grade' => $this->display(ClassOfCalculator::gradeFromClassOf($classOf, $school->senior_year)),
                'home_school' => $this->display($student->homeSchool?->name),
                'teachers' => (string) $teacherNames->get("{$enrollment->student_id}:{$enrollment->school_id}", ''),
                'address.address1' => $this->display($address?->address1),
                'address.address2' => $this->display($address?->address2),
                'address.city' => $this->display($address?->city),
                'address.state' => $this->display($address?->geo_state),
                'address.zip_code' => $this->display($address?->zip_code),
            ];

            $contacts = $student->emergencyContacts->sortBy('id')->values();

            for ($i = 0; $i < $guardianCount; $i++) {
                $row += $this->guardianFields('guardian_'.($i + 1), $contacts->get($i));
            }

            return $row;
        })->all();
    }

    /**
     * One row per Candidate on this Version, with scores (one column per
     * category/judge/factor), result, fees/waivers/paid, and upload and
     * recording file names.
     *
     * @return list<array<string, string>>
     */
    public function candidateRows(Version $version): array
    {
        $version->loadMissing('fees');

        $candidates = Candidate::where('version_id', $version->id)
            ->with([
                'student.user',
                'school',
                'teacher.user',
                'voicePart',
                'acceptedEnsemble',
                'emergencyContact',
                'applicationCertifiedBy',
                'feeWaivers',
                'uploadFiles.versionUploadFile',
            ])
            ->get()
            ->sortBy(fn (Candidate $c): string => mb_strtolower($c->student->user->last_name.' '.$c->student->user->first_name))
            ->values();

        $candidateIds = $candidates->pluck('id');

        $scores = Score::where('version_id', $version->id)
            ->whereIn('candidate_id', $candidateIds)
            ->with(['scoreCategory', 'scoreFactor', 'judge'])
            ->get();

        $scoreKey = fn (Score $score): string => 'score.'
            .$score->scoreCategory->description.'.'
            .JudgeType::from($score->judge->getRawOriginal('judge_type'))->label().'.'
            .$score->scoreFactor->abbreviation;

        $scoreColumns = $scores
            ->sortBy(fn (Score $s): array => [$s->score_category_order_by, $s->judge_order_by, $s->scoreFactor->abbreviation])
            ->map($scoreKey)
            ->unique()
            ->values();

        $scoresByCandidate = $scores->groupBy('candidate_id')
            ->map(fn (Collection $group): Collection => $group->mapWithKeys(fn (Score $s): array => [$scoreKey($s) => $s->score]));

        $totals = $this->scoreTotals($version, $candidates->filter(fn (Candidate $c): bool => $scoresByCandidate->has($c->id)));

        $paidByCandidate = PaymentAllocation::whereIn('candidate_id', $candidateIds)
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', PaymentTransactionStatus::Completed->value))
            ->get()
            ->groupBy('candidate_id')
            ->map(fn (Collection $g): int => (int) $g->sum('amount'));

        $recordingsByCandidate = Recording::whereIn('candidate_id', $candidateIds)->get()->groupBy('candidate_id');

        $uploadColumns = $candidates->flatMap(fn (Candidate $c): Collection => $c->uploadFiles)
            ->sortBy(fn (CandidateUploadFile $file): array => [$file->versionUploadFile === null ? PHP_INT_MAX : $file->versionUploadFile->order_by, $this->uploadName($file)])
            ->map(fn (CandidateUploadFile $file): string => $this->uploadName($file))
            ->unique()
            ->values();

        $recordingColumns = $recordingsByCandidate->flatten()->pluck('file_type')->map(fn ($t): string => (string) $t)->unique()->sort()->values();

        $owedStatusValues = array_map(fn (CandidateStatus $s): string => $s->value, CandidateStatus::registrationFeeOwedStates());

        return $candidates->map(function (Candidate $candidate) use (
            $version, $scoreColumns, $scoresByCandidate, $totals, $paidByCandidate,
            $recordingsByCandidate, $uploadColumns, $recordingColumns, $owedStatusValues,
        ): array {
            $user = $candidate->student->user;
            $contact = $candidate->emergencyContact;
            $owes = in_array($candidate->getRawOriginal('status'), $owedStatusValues, true);
            $dueCents = $owes ? $this->fees->combinedDueCents($version, $candidate) : 0;
            $paidCents = $paidByCandidate->get($candidate->id, 0);

            $row = [
                'ref' => (string) $candidate->ref,
                'first_name' => $this->display($user->first_name),
                'middle_name' => $this->display($user->middle_name),
                'last_name' => $this->display($user->last_name),
                'suffix_name' => $this->display($user->suffix_name),
                'email' => $this->display($user->email),
                'status' => $this->display($candidate->status),
                'school' => $this->display($candidate->school?->name),
                'teacher' => $this->display($candidate->teacher?->user->name),
                'voice_part' => $this->display($candidate->voicePart?->name),
                'program_name' => $this->display($candidate->program_name),
                'emergency_contact.name' => $this->display($contact?->name),
                'emergency_contact.relationship' => $this->rawEnum($contact, 'relationship', EmergencyContactRelationship::class),
                'emergency_contact.email' => $this->display($contact?->email),
                'emergency_contact.phone' => $this->display($contact?->preferred_phone),
                'application.candidate_signed_at' => $this->dateTime($candidate->application_candidate_signed_at),
                'application.parent_signed_at' => $this->dateTime($candidate->application_parent_signed_at),
                'application.certified_at' => $this->dateTime($candidate->application_certified_at),
                'application.certified_by' => $this->display($candidate->applicationCertifiedBy?->name),
            ];

            $candidateScores = $scoresByCandidate->get($candidate->id) ?? collect();

            foreach ($scoreColumns as $column) {
                $row[$column] = $this->display($candidateScores->get($column));
            }

            $row['score.total'] = $this->display($totals[$candidate->id] ?? null);
            $row['result.accepted_ensemble'] = $this->display($candidate->acceptedEnsemble?->name);

            $row['fee.registration'] = $this->dollars($this->fees->feeCents($version, $candidate, FeeType::Registration));
            $row['fee.participation'] = $this->dollars($this->fees->feeCents($version, $candidate, FeeType::Participation));
            $row['fee.housing'] = $this->dollars($this->fees->feeCents($version, $candidate, FeeType::Housing));
            $row['fee.waivers'] = $candidate->feeWaivers
                ->map(fn ($w): string => $this->rawEnum($w, 'fee_type', FeeType::class).($w->reason ? " ({$w->reason})" : ''))
                ->implode('; ');
            $row['fee.waived_amount'] = $this->dollars($owes ? $this->fees->combinedWaivedCents($version, $candidate) : 0);
            $row['fee.due'] = $this->dollars($dueCents);
            $row['fee.paid'] = $this->dollars($paidCents);
            $row['fee.balance'] = $this->dollars($dueCents - $paidCents);

            $uploads = $candidate->uploadFiles->keyBy(fn (CandidateUploadFile $file): string => $this->uploadName($file));

            foreach ($uploadColumns as $name) {
                $file = $uploads->get($name);
                $row["upload.{$name}"] = $file === null ? '' : $this->uploadFileName($file);
                $row["upload.{$name}.status"] = $this->rawEnum($file, 'status', CandidateUploadStatus::class);
            }

            $recordings = ($recordingsByCandidate->get($candidate->id) ?? collect())->keyBy(fn ($r): string => (string) $r->file_type);

            foreach ($recordingColumns as $type) {
                $recording = $recordings->get($type);
                $row["recording.{$type}"] = $recording?->url ? basename((string) parse_url($recording->url, PHP_URL_PATH)) : '';
            }

            return $row;
        })->all();
    }

    /**
     * The Version upload-file slot a candidate file fills (its column name).
     */
    private function uploadName(CandidateUploadFile $file): string
    {
        $slot = $file->versionUploadFile;

        return $slot === null ? 'file' : (string) $slot->name;
    }

    /**
     * The file's original name, falling back to the stored path's basename.
     */
    private function uploadFileName(CandidateUploadFile $file): string
    {
        $original = $file->original_filename;

        if ($original !== null && $original !== '') {
            return $original;
        }

        return $file->url === '' ? '' : basename($file->url);
    }

    /**
     * Every owned teacher's active school_teacher rows, sorted by teacher
     * then school.
     *
     * @return Collection<int, SchoolTeacher>
     */
    private function teacherSchools(Version $version): Collection
    {
        return SchoolTeacher::where('is_active', true)
            ->whereIn('teacher_id', $this->invitations($version)->pluck('teacher_id'))
            ->with(['school.county', 'teacher.user.pronoun', 'teacher.user.phones', 'replacedTeacher.user', 'subjects'])
            ->get()
            ->sortBy(fn (SchoolTeacher $row): string => mb_strtolower($row->teacher->user->last_name.' '.$row->teacher->user->first_name.' '.$row->school->name))
            ->values();
    }

    /**
     * @return Collection<int, VersionInvitation>
     */
    private function invitations(Version $version): Collection
    {
        return VersionInvitation::where('version_id', $version->id)
            ->with(['teacher', 'obligationResponse'])
            ->get()
            ->filter(fn (VersionInvitation $invitation): bool => $invitation->teacher !== null)
            ->unique('teacher_id')
            ->values();
    }

    /**
     * Grand totals for candidates that have scores, batched per Voice Part
     * (EnsembleCutoffService::voicePartTotals()) rather than per candidate.
     *
     * @param  Collection<int, Candidate>  $candidates
     * @return array<int, int>
     */
    private function scoreTotals(Version $version, Collection $candidates): array
    {
        $totals = [];

        foreach ($candidates->groupBy('voice_part_id') as $group) {
            $voicePart = $group->first()->voicePart;

            if (! $voicePart instanceof VoicePart) {
                continue;
            }

            $totals += $this->cutoffs->voicePartTotals($version, $voicePart, $group->pluck('id'));
        }

        return $totals;
    }

    /**
     * Non-credential user data, keyed for CSV.
     *
     * @return array<string, string>
     */
    private function userFields(User $user): array
    {
        $phones = $user->phones->keyBy(fn (Phone $p): string => (string) $p->getRawOriginal('type'));

        return [
            'honorific' => $this->display($user->honorific),
            'first_name' => $this->display($user->first_name),
            'middle_name' => $this->display($user->middle_name),
            'last_name' => $this->display($user->last_name),
            'suffix_name' => $this->display($user->suffix_name),
            'pronoun' => $this->display($user->pronoun?->description),
            'email' => $this->display($user->email),
            'email_verified_at' => $this->dateTime($user->email_verified_at),
            'email_unverifiable' => $this->display((bool) $user->email_unverifiable),
            'cell_phone' => $this->display($user->cell_phone),
            'phone.cell' => $this->display($phones->get('cell')?->formatted),
            'phone.home' => $this->display($phones->get('home')?->formatted),
            'phone.work' => $this->display($phones->get('work')?->formatted),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function guardianFields(string $prefix, ?EmergencyContact $contact): array
    {
        return [
            "{$prefix}.name" => $this->display($contact?->name),
            "{$prefix}.relationship" => $this->rawEnum($contact, 'relationship', EmergencyContactRelationship::class),
            "{$prefix}.email" => $this->display($contact?->email),
            "{$prefix}.cell_phone" => $this->display($contact?->cell_phone),
            "{$prefix}.home_phone" => $this->display($contact?->home_phone),
            "{$prefix}.work_phone" => $this->display($contact?->work_phone),
            "{$prefix}.best_phone" => $this->rawEnum($contact, 'best_phone', BestPhone::class),
        ];
    }

    /**
     * Reads an enum-cast attribute from its raw value — legacy rows hold
     * values (e.g. '' for best_phone) the enum cast would throw on. Falls
     * back to the raw string when it isn't a valid case.
     *
     * @param  class-string<BackedEnum>  $enumClass
     */
    private function rawEnum(?Model $model, string $attribute, string $enumClass): string
    {
        $raw = $model?->getRawOriginal($attribute);

        if ($raw === null || $raw === '') {
            return '';
        }

        $case = $enumClass::tryFrom($raw);

        return $case === null ? (string) $raw : $this->display($case);
    }

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof BackedEnum => method_exists($value, 'label') ? (string) $value->label() : (string) $value->value,
            $value instanceof CarbonInterface => $this->dateTime($value),
            default => (string) $value,
        };
    }

    /**
     * Stored-UTC datetimes rendered in the app's display timezone.
     */
    private function dateTime(CarbonInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $carbon = $value instanceof CarbonInterface ? $value->copy() : Carbon::parse($value);

        return $carbon->timezone((string) config('app.display_timezone'))->format('Y-m-d H:i');
    }

    /**
     * Calendar dates (`date` casts) — no timezone shift, which would roll a
     * midnight-UTC date back a day.
     */
    private function date(CarbonInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return ($value instanceof CarbonInterface ? $value : Carbon::parse($value))->format('Y-m-d');
    }

    private function dollars(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}
