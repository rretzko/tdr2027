<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\TeacherPayments;
use App\Enums\UploadType;
use App\Models\Ensemble;
use App\Models\Version;
use App\Services\Readiness\SetupAnswers;
use App\Services\Readiness\SetupQuestionnaire;
use App\Services\Readiness\VersionReadiness;
use App\Services\VersionRoleAssignmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The setup questions (docs/plans/version-readiness-setup-questions.md):
 * a short questionnaire shown after an Event's first Version is created,
 * and re-runnable from the setup checklist. Answers are applied by
 * SetupQuestionnaire; blank answers write nothing.
 */
#[Layout('components.layouts.app')]
class VersionSetupQuestions extends Component
{
    /**
     * Part 2, keyed by field name (readiness item keys contain dots, which
     * wire:model would treat as nesting).
     *
     * @var array<string, array{key: string, question: string, help: string}>
     */
    private const OPTIONAL_QUESTIONS = [
        'counties' => [
            'key' => 'version.counties',
            'question' => 'Is the event limited to schools in certain counties?',
            'help' => '"No" means any school can be invited.',
        ],
        'co_registration' => [
            'key' => 'version.roles.co_registration',
            'question' => 'Will county co-managers share registration work?',
            'help' => 'Co-Registration Managers handle the schools in their counties.',
        ],
        'obligations' => [
            'key' => 'version.obligations',
            'question' => 'Must teachers agree to obligations before taking part?',
            'help' => 'For example, attendance and conduct policies teachers accept before registering students.',
        ],
        'pitch_files' => [
            'key' => 'version.pitch_files',
            'question' => 'Will you provide practice tracks or pitch files?',
            'help' => 'Audio or reference files students and teachers download to prepare.',
        ],
        'caps' => [
            'key' => 'version.caps',
            'question' => 'Is there a cap on registrants — overall, per school, or for upper voices?',
            'help' => 'Caps stop registration once they are reached.',
        ],
    ];

    private const MAX_LIST_ENTRIES = 10;

    public Version $version;

    public string $audition_type = '';

    public string $upload_type = '';

    public string $audition_timeslot = '20';

    /** One recording name per line. */
    public string $recording_names = '';

    public string $application_type = '';

    /** 'yes' | 'no' | '' */
    public string $mail_required = '';

    public string $membership_card = '';

    public string $membership_valid_thru = '';

    /** Q4a — TeacherPayments value, or '' */
    public string $teacher_payments = '';

    /** Q4b — 'yes' | 'no' | '' */
    public string $student_payments = '';

    /** One ensemble name per line. */
    public string $ensemble_names = '';

    public string $judge_count = '';

    /** @var array<string, string> Part 2, keyed by OPTIONAL_QUESTIONS field: 'yes' | 'no' | '' */
    public array $optional = [];

    public function mount(Version $version, VersionRoleAssignmentService $roles, SetupQuestionnaire $questionnaire): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $version->event), 403);

        $this->version = $version;
        $current = $questionnaire->current($version);

        $this->audition_type = $current->auditionType->value ?? '';
        $this->upload_type = $current->uploadType->value ?? '';
        $this->audition_timeslot = (string) ($current->auditionTimeslot ?? 20);
        $this->application_type = $current->applicationType->value ?? '';
        $this->mail_required = $this->yesNo($current->mailRequired);
        $this->membership_card = $this->yesNo($current->membershipCard);
        $this->membership_valid_thru = $current->membershipValidThru ?? '';
        $this->teacher_payments = $current->teacherPayments->value ?? '';
        $this->student_payments = $this->yesNo($current->studentPayments);
        $this->judge_count = $current->judgeCount !== null ? (string) $current->judgeCount : '';

        foreach (self::OPTIONAL_QUESTIONS as $field => $q) {
            $this->optional[$field] = $this->yesNo($current->optional[$q['key']] ?? null);
        }
    }

    /** Decision G: a paper application usually means mailing — pre-select it, changeable. */
    public function updatedApplicationType(string $value): void
    {
        if ($value === ApplicationType::Pdf->value && $this->mail_required === '') {
            $this->mail_required = 'yes';
        }
    }

    public function save(SetupQuestionnaire $questionnaire, VersionRoleAssignmentService $roles): void
    {
        abort_unless($roles->canManageEvent(Auth::user(), $this->version->event), 403);

        $this->validate([
            'audition_type' => ['nullable', 'in:'.AuditionType::Remote->value.','.AuditionType::InPerson->value],
            'upload_type' => ['nullable', 'in:'.UploadType::Audio->value.','.UploadType::Video->value],
            'audition_timeslot' => ['nullable', 'integer', 'min:5', 'max:120'],
            'recording_names' => ['nullable', 'string', $this->listRule(100)],
            'application_type' => ['nullable', 'in:'.ApplicationType::Pdf->value.','.ApplicationType::EApplication->value],
            'mail_required' => ['nullable', 'in:yes,no'],
            'membership_card' => ['nullable', 'in:yes,no'],
            'membership_valid_thru' => ['nullable', 'date'],
            'teacher_payments' => ['nullable', 'in:'.implode(',', array_column(TeacherPayments::cases(), 'value'))],
            'student_payments' => ['nullable', 'in:yes,no'],
            'ensemble_names' => ['nullable', 'string', $this->listRule(255)],
            'judge_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'optional.*' => ['nullable', 'in:yes,no'],
        ], [], [
            'audition_timeslot' => 'audition length',
            'recording_names' => 'recordings',
            'membership_valid_thru' => 'membership date',
            'ensemble_names' => 'ensembles',
            'judge_count' => 'number of judges',
        ]);

        $questionnaire->apply($this->version, new SetupAnswers(
            auditionType: AuditionType::tryFrom($this->audition_type),
            uploadType: UploadType::tryFrom($this->upload_type),
            auditionTimeslot: $this->audition_timeslot !== '' ? (int) $this->audition_timeslot : null,
            recordingNames: $this->lines($this->recording_names),
            applicationType: ApplicationType::tryFrom($this->application_type),
            mailRequired: $this->bool($this->mail_required),
            membershipCard: $this->bool($this->membership_card),
            membershipValidThru: $this->membership_valid_thru !== '' ? $this->membership_valid_thru : null,
            teacherPayments: TeacherPayments::tryFrom($this->teacher_payments),
            studentPayments: $this->bool($this->student_payments),
            ensembleNames: $this->lines($this->ensemble_names),
            judgeCount: $this->judge_count !== '' ? (int) $this->judge_count : null,
            optional: collect(self::OPTIONAL_QUESTIONS)
                ->mapWithKeys(fn (array $q, string $field): array => [$q['key'] => $this->bool($this->optional[$field] ?? '')])
                ->all(),
        ), Auth::user());

        Flux::toast(text: "Setup answers saved for {$this->version->name}.", variant: 'success');

        $this->redirectRoute('events.versions.readiness', $this->version, navigate: true);
    }

    public function render(SetupQuestionnaire $questionnaire, VersionReadiness $readiness): View
    {
        $existing = $questionnaire->existingOptionalSetup($this->version);

        return view('livewire.events.version-setup-questions', [
            'existingRecordings' => $this->version->uploadFiles()->orderBy('order_by')->pluck('name'),
            'existingEnsembles' => Ensemble::where('event_id', $this->version->event_id)->orderBy('name')->pluck('name'),
            'optionalQuestions' => collect(self::OPTIONAL_QUESTIONS)->map(fn (array $q, string $field): array => [
                'field' => $field,
                'question' => $q['question'],
                'help' => $q['help'],
                'existing' => $existing[$q['key']],
                'url' => $readiness->item($q['key'])?->urlFor($this->version),
            ])->values(),
            'teacherPaymentOptions' => TeacherPayments::cases(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
    }

    /**
     * One entry per line: at most MAX_LIST_ENTRIES, each at most $maxLength characters.
     */
    private function listRule(int $maxLength): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($maxLength): void {
            $lines = $this->lines((string) $value);

            if (count($lines) > self::MAX_LIST_ENTRIES) {
                $fail('List at most '.self::MAX_LIST_ENTRIES.' — you can add more later.');
            } elseif (collect($lines)->contains(fn (string $line): bool => mb_strlen($line) > $maxLength)) {
                $fail("Each line must be {$maxLength} characters or fewer.");
            }
        };
    }

    private function yesNo(?bool $value): string
    {
        return $value === null ? '' : ($value ? 'yes' : 'no');
    }

    private function bool(string $value): ?bool
    {
        return $value === '' ? null : $value === 'yes';
    }
}
