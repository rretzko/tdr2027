<?php

declare(strict_types=1);

namespace App\Services\Readiness;

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\TeacherPayments;
use App\Enums\UploadType;

/**
 * Answers to the setup questions (docs/plans/version-readiness-setup-questions.md).
 * Every field is nullable: null means "not answered", and an unanswered
 * question writes nothing and confirms nothing.
 */
final readonly class SetupAnswers
{
    /**
     * @param  list<string>  $recordingNames  Q1a-ii — only used when the Version has none yet
     * @param  list<string>  $ensembleNames  Q5 — only used when the Event has none yet
     * @param  array<string, ?bool>  $optional  Part 2, keyed by readiness item key: true = "yes", false = "no"
     */
    public function __construct(
        public ?AuditionType $auditionType = null,
        public ?UploadType $uploadType = null,
        public ?int $auditionTimeslot = null,
        public array $recordingNames = [],
        public ?ApplicationType $applicationType = null,
        public ?bool $mailRequired = null,
        public ?bool $membershipCard = null,
        public ?string $membershipValidThru = null,
        public ?TeacherPayments $teacherPayments = null,
        public ?bool $studentPayments = null,
        public array $ensembleNames = [],
        public ?int $judgeCount = null,
        public array $optional = [],
    ) {}
}
