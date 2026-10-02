<?php

declare(strict_types=1);

namespace App\Services\Readiness;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\ReadinessReviewState;
use App\Enums\TeacherPayments;
use App\Enums\UploadType;
use App\Models\Ensemble;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionEpaymentConfig;
use App\Models\VersionMembershipRequirement;
use App\Services\VersionRoleAssignmentService;
use Illuminate\Support\Facades\DB;

/**
 * The setup questions an Event Manager answers when an Event's first
 * Version is created (docs/plans/version-readiness-setup-questions.md).
 * Every answer writes an existing setting and confirms the matching
 * readiness item; nothing is ever deleted, so re-running is safe.
 */
class SetupQuestionnaire
{
    /** Part 2 — optional features; a "no" confirms the item as "we don't use this". */
    public const OPTIONAL_ITEMS = [
        'version.counties',
        'version.roles.co_registration',
        'version.obligations',
        'version.pitch_files',
        'version.caps',
    ];

    public function __construct(
        private readonly VersionReadiness $readiness,
        private readonly VersionRoleAssignmentService $roles,
    ) {}

    /**
     * Answers as they stand. A question is pre-filled only when its decision
     * has already been made (confirmed via an earlier run, a Configure save,
     * or "Looks right"), so a first run starts blank.
     */
    public function current(Version $version): SetupAnswers
    {
        $version->loadMissing(['readinessReviews', 'membershipRequirement', 'versionEpaymentConfig']);

        $format = $this->reviewed($version, 'version.audition_format');
        $uploadType = $version->getRawOriginal('upload_type');
        $timeslot = (int) $version->getRawOriginal('audition_timeslot');
        $epayment = $version->versionEpaymentConfig;
        $existing = $this->existingOptionalSetup($version);

        return new SetupAnswers(
            auditionType: $format ? AuditionType::from($version->getRawOriginal('audition_type')) : null,
            uploadType: $format && $uploadType !== UploadType::None->value ? UploadType::from($uploadType) : null,
            auditionTimeslot: $timeslot > 0 ? $timeslot : null,
            applicationType: $format ? ApplicationType::from($version->getRawOriginal('application_type')) : null,
            mailRequired: $format ? (bool) $version->getRawOriginal('mail_required') : null,
            membershipCard: $this->reviewed($version, 'version.membership')
                ? (bool) $version->membershipRequirement?->membership_card
                : null,
            membershipValidThru: $version->membershipRequirement?->getRawOriginal('valid_thru'),
            teacherPayments: $this->reviewed($version, 'version.epayment.decision')
                ? TeacherPayments::fromFlags((bool) $epayment?->epayment_teacher, (bool) $epayment?->online_payment_required)
                : null,
            studentPayments: $this->reviewed($version, 'version.epayment.decision') ? (bool) $epayment?->epayment_student : null,
            judgeCount: $this->reviewed($version, 'version.judge_count') ? (int) $version->getRawOriginal('judge_count') : null,
            optional: collect(self::OPTIONAL_ITEMS)->mapWithKeys(fn (string $key): array => [
                $key => match (true) {
                    $existing[$key] !== null => true,
                    $this->reviewed($version, $key) => false,
                    default => null,
                },
            ])->all(),
        );
    }

    /**
     * Part 2 features that already have data, as a short description (null
     * when there's none). The page shows these as "already set up" instead
     * of a yes/no — so a "no" can never imply deleting anything.
     *
     * @return array<string, ?string> keyed by readiness item key
     */
    public function existingOptionalSetup(Version $version): array
    {
        $version->loadMissing(['counties', 'obligation', 'pitchFiles']);

        $coManagers = $this->roles->assignmentsForVersion($version)->get('Co-Registration Manager', collect())->count();
        // Same rules registration enforces: for the overall and upper-voice
        // caps, 0 means "no limit" (EstimateFormData) — and 0 is the column
        // default for upper voices; the per-school cap is only off when null
        // (AuditionCapService), so a 0 there is a real cap.
        $caps = collect([
            (int) $version->getRawOriginal('max_registrants') > 0,
            (int) $version->getRawOriginal('max_upper_voice_registrants') > 0,
            $version->getRawOriginal('audition_cap_per_school') !== null,
        ])->filter()->count();

        return [
            'version.counties' => $version->counties->isNotEmpty() ? $version->counties->count().' '.str('county')->plural($version->counties->count()).' selected' : null,
            'version.roles.co_registration' => $coManagers > 0 ? $coManagers.' '.str('co-manager')->plural($coManagers).' assigned' : null,
            'version.obligations' => $version->obligation !== null ? 'Obligations written' : null,
            'version.pitch_files' => $version->pitchFiles->isNotEmpty() ? $version->pitchFiles->count().' '.str('file')->plural($version->pitchFiles->count()).' uploaded' : null,
            'version.caps' => $caps > 0 ? $caps.' '.str('cap')->plural($caps).' set' : null,
        ];
    }

    public function apply(Version $version, SetupAnswers $answers, User $user): void
    {
        DB::transaction(function () use ($version, $answers, $user): void {
            $this->applyFormat($version, $answers, $user);
            $this->applyMembership($version, $answers, $user);
            $this->applyPayments($version, $answers, $user);
            $this->applyEnsembles($version, $answers);

            if ($answers->judgeCount !== null) {
                $version->update(['judge_count' => $answers->judgeCount]);
                $this->readiness->acknowledge($version, 'version.judge_count', $user);
            }

            $existing = $this->existingOptionalSetup($version);
            foreach (self::OPTIONAL_ITEMS as $key) {
                // "No" confirms the feature isn't used; "yes" leaves it open on
                // the checklist. Features with data already aren't asked.
                if (($answers->optional[$key] ?? null) === false && $existing[$key] === null) {
                    $this->readiness->acknowledge($version, $key, $user);
                }
            }
        });
    }

    /**
     * Q1, Q1a, Q1a-ii, Q1b, Q2, Q2b. In person always means no uploads —
     * students only see upload slots for remote auditions (decision A).
     */
    private function applyFormat(Version $version, SetupAnswers $answers, User $user): void
    {
        $updates = [];

        if ($answers->auditionType === AuditionType::InPerson) {
            $updates['audition_type'] = AuditionType::InPerson->value;
            $updates['upload_type'] = UploadType::None->value;
            $updates['audition_timeslot'] = $answers->auditionTimeslot ?? 20;
        } elseif ($answers->auditionType === AuditionType::Remote) {
            $updates['audition_type'] = AuditionType::Remote->value;

            if ($answers->uploadType !== null && $answers->uploadType !== UploadType::None) {
                $updates['upload_type'] = $answers->uploadType->value;
            }
        }

        if ($answers->applicationType !== null) {
            $updates['application_type'] = $answers->applicationType->value;
        }

        if ($answers->mailRequired !== null) {
            $updates['mail_required'] = $answers->mailRequired;
        }

        if ($updates !== []) {
            $version->update($updates);
        }

        if ($answers->auditionType === AuditionType::Remote && $answers->recordingNames !== [] && ! $version->uploadFiles()->exists()) {
            foreach ($answers->recordingNames as $index => $name) {
                $version->uploadFiles()->create(['name' => $name, 'order_by' => $index + 1]);
            }
        }

        // The format decision is made once how students audition (and, for
        // recordings, in what medium) and how they apply are both answered.
        $auditionDecided = $answers->auditionType === AuditionType::InPerson
            || ($answers->auditionType === AuditionType::Remote && $answers->uploadType !== null);

        if ($auditionDecided && $answers->applicationType !== null) {
            $this->readiness->acknowledge($version, 'version.audition_format', $user);
        }
    }

    private function applyMembership(Version $version, SetupAnswers $answers, User $user): void
    {
        if ($answers->membershipCard === null) {
            return;
        }

        VersionMembershipRequirement::updateOrCreate(
            ['version_id' => $version->id],
            [
                'membership_card' => $answers->membershipCard,
                'valid_thru' => $answers->membershipCard ? $answers->membershipValidThru : null,
            ],
        );

        $this->readiness->acknowledge($version, 'version.membership', $user);
    }

    /**
     * Q4a (how teachers settle their balance) and Q4b (whether teachers may
     * let their students pay online) are independent; each writes only its
     * own flags. The payment decision is confirmed once both are answered.
     */
    private function applyPayments(Version $version, SetupAnswers $answers, User $user): void
    {
        $updates = [];

        if ($answers->teacherPayments !== null) {
            $updates['epayment_teacher'] = $answers->teacherPayments->epaymentTeacher();
            $updates['online_payment_required'] = $answers->teacherPayments->onlineRequired();
        }

        if ($answers->studentPayments !== null) {
            $updates['epayment_student'] = $answers->studentPayments;
        }

        if ($updates === []) {
            return;
        }

        VersionEpaymentConfig::updateOrCreate(['version_id' => $version->id], $updates);

        if ($answers->teacherPayments !== null && $answers->studentPayments !== null) {
            $this->readiness->acknowledge($version, 'version.epayment.decision', $user);
        }
    }

    /** Q5 — only when the Event has no ensembles yet (decision B: names only). */
    private function applyEnsembles(Version $version, SetupAnswers $answers): void
    {
        if ($answers->ensembleNames === [] || Ensemble::where('event_id', $version->event_id)->exists()) {
            return;
        }

        foreach ($answers->ensembleNames as $name) {
            Ensemble::create(['event_id' => $version->event_id, 'name' => $name]);
        }
    }

    private function reviewed(Version $version, string $key): bool
    {
        return $version->readinessReviews->first(
            fn ($review): bool => $review->item_key === $key
                && $review->getRawOriginal('state') === ReadinessReviewState::Acknowledged->value,
        ) !== null;
    }
}
