<?php

declare(strict_types=1);

namespace App\Services\Readiness;

use App\Enums\AuditionType;
use App\Enums\ReadinessEditor;
use App\Enums\ReadinessPhase;
use App\Enums\ReadinessStatus;
use App\Enums\ScoreOrder;
use App\Enums\TeacherPayments;
use App\Enums\UploadType;
use App\Enums\VersionDateType;
use App\Models\Ensemble;
use App\Models\ScoreCategory;
use App\Models\Version;
use App\Models\VersionRoom;
use Carbon\Carbon;
use Closure;

/**
 * The single list of Version readiness items. Adding a Version setting?
 * Add (or extend) an item here — VersionReadinessCoverageTest fails until
 * every Version column is covered or explicitly ignored.
 *
 * Copy is written for a choir director, not for TDR's data model: the
 * question names the decision, `why` names what goes wrong if it's skipped.
 */
final class ReadinessCatalog
{
    /**
     * @return list<ReadinessItem>
     */
    public static function items(): array
    {
        return [
            ...self::structure(),
            ...self::beforeInvitations(),
            ...self::beforeRegistration(),
            ...self::beforeAuditions(),
            ...self::beforeResults(),
        ];
    }

    /**
     * Current US school year's graduating class — same rule as
     * School::getSeniorYearAttribute().
     */
    public static function currentSeniorClass(): int
    {
        $now = Carbon::now();

        return $now->month <= 6 ? $now->year : $now->year + 1;
    }

    /**
     * @return list<ReadinessItem>
     */
    private static function structure(): array
    {
        $phase = ReadinessPhase::Structure;

        return [
            new ReadinessItem(
                key: 'event.ensembles',
                phase: $phase,
                question: 'What ensembles will students be placed into?',
                why: 'Students audition for placement in an ensemble — with none defined there is nothing to audition for.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->ensembles->isNotEmpty() ? ReadinessStatus::Done : ReadinessStatus::NotStarted,
                url: self::ensemblesTab(),
                detail: fn (ReadinessContext $c): ?string => $c->ensembles->isNotEmpty() ? $c->ensembles->count().' '.str('ensemble')->plural($c->ensembles->count()) : null,
                covers: ['ensembles.name'],
            ),
            new ReadinessItem(
                key: 'event.ensemble_grades',
                phase: $phase,
                question: 'Which grades can sing in each ensemble?',
                why: 'Grades decide which students are eligible for which ensemble.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => self::fraction(
                    $c->ensembles->filter(fn (Ensemble $e): bool => $e->grades->isNotEmpty())->count(),
                    $c->ensembles->count(),
                ),
                url: self::ensemblesTab(),
                applicable: fn (ReadinessContext $c): bool => $c->ensembles->isNotEmpty(),
                detail: fn (ReadinessContext $c): string => $c->ensembles->filter(fn (Ensemble $e): bool => $e->grades->isNotEmpty())->count().' of '.$c->ensembles->count().' ensembles have grades',
                covers: ['ensemble_grades.grade'],
            ),
            new ReadinessItem(
                key: 'event.ensemble_voice_parts',
                phase: $phase,
                question: 'Which voice parts does each ensemble use?',
                why: 'Students register by voice part, and judges and cut-offs are organized by voice part.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => self::fraction(
                    $c->ensembles->filter(fn (Ensemble $e): bool => $e->voiceParts->isNotEmpty())->count(),
                    $c->ensembles->count(),
                ),
                url: self::ensemblesTab(),
                applicable: fn (ReadinessContext $c): bool => $c->ensembles->isNotEmpty(),
                detail: fn (ReadinessContext $c): string => $c->ensembles->filter(fn (Ensemble $e): bool => $e->voiceParts->isNotEmpty())->count().' of '.$c->ensembles->count().' ensembles have voice parts',
                covers: ['ensemble_voice_parts.voice_part_id'],
            ),
            new ReadinessItem(
                key: 'event.logo',
                phase: $phase,
                question: 'Do you want your logo on applications and reports?',
                why: 'Optional — without one, documents show the event name only.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => filled($c->version->event->logo_url) ? ReadinessStatus::Done : ReadinessStatus::NeedsReview,
                url: fn (Version $v): string => route('events.show', $v->event),
                blocking: false,
                acknowledgeable: true,
                covers: ['events.logo_url', 'events.logo_alt'],
            ),
            new ReadinessItem(
                key: 'version.ensemble_order',
                phase: $phase,
                question: 'In what order are ensembles filled?',
                why: 'Cut-offs fill ensembles in this order — the top ensemble usually goes first.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->version->ensembleOrder->count() >= $c->ensembles->count()
                    ? ReadinessStatus::Done
                    : ReadinessStatus::NotStarted,
                url: self::editTab('general'),
                applicable: fn (ReadinessContext $c): bool => $c->ensembles->count() > 1,
                section: 'general',
                covers: ['version_ensemble_order.order_by'],
            ),
            new ReadinessItem(
                key: 'version.rubric',
                phase: $phase,
                question: 'How will judges score? (categories and factors)',
                why: 'The scoring rubric is what judges fill in — without it nobody can be scored.',
                resolve: function (ReadinessContext $c): ReadinessStatus {
                    if ($c->rubric->isEmpty()) {
                        return ReadinessStatus::NotStarted;
                    }

                    return $c->rubric->every(fn (ScoreCategory $cat): bool => (int) $cat->score_factors_count > 0)
                        ? ReadinessStatus::NeedsReview
                        : ReadinessStatus::InProgress;
                },
                url: fn (Version $v): string => route('events.versions.scoring-rubric', $v),
                detail: fn (ReadinessContext $c): ?string => $c->rubric->isNotEmpty()
                    ? $c->rubric->count().' '.str('category')->plural($c->rubric->count()).', '.$c->rubric->sum('score_factors_count').' factors'
                    : null,
                acknowledgeable: true,
                section: 'rubric',
                covers: ['score_categories.description', 'score_factors.description'],
                editor: ReadinessEditor::AuditionEnvironment,
            ),
        ];
    }

    /**
     * @return list<ReadinessItem>
     */
    private static function beforeInvitations(): array
    {
        $phase = ReadinessPhase::BeforeInvitations;

        return [
            new ReadinessItem(
                key: 'version.identity',
                phase: $phase,
                question: "What is this year's name, and which class graduates this year?",
                why: 'The graduating class drives every student\'s grade — if it\'s last year\'s, every student shows up a grade too young.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => (int) $c->raw('senior_class_of') >= self::currentSeniorClass()
                    ? ReadinessStatus::Done
                    : ReadinessStatus::InProgress,
                url: self::editTab('general'),
                detail: fn (ReadinessContext $c): ?string => (int) $c->raw('senior_class_of') < self::currentSeniorClass()
                    ? 'Graduating class is '.$c->raw('senior_class_of').'; this school year\'s seniors are the class of '.self::currentSeniorClass()
                    : null,
                yearSensitive: true,
                section: 'general',
                covers: ['versions.name', 'versions.short_name', 'versions.senior_class_of'],
            ),
            new ReadinessItem(
                key: 'version.eligible_grades',
                phase: $phase,
                question: 'Which grades may audition this year?',
                why: 'Leave empty to allow every grade your ensembles accept; set it to narrow eligibility for this year only.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->version->classOfs->isNotEmpty() ? ReadinessStatus::Done : ReadinessStatus::NeedsReview,
                url: self::editTab('general'),
                detail: fn (ReadinessContext $c): string => $c->version->classOfs->isEmpty() ? 'All ensemble grades eligible' : $c->version->classOfs->count().' grades selected',
                blocking: false,
                acknowledgeable: true,
                yearSensitive: true,
                section: 'general',
                covers: ['version_class_ofs.class_of'],
            ),
            new ReadinessItem(
                key: 'version.counties',
                phase: $phase,
                question: 'Is this event limited to certain counties?',
                why: 'Leave empty if any school may take part; otherwise only schools in the chosen counties can be invited.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->version->counties->isNotEmpty() ? ReadinessStatus::Done : ReadinessStatus::NeedsReview,
                url: self::editTab('requirements'),
                detail: fn (ReadinessContext $c): string => $c->version->counties->isEmpty() ? 'No county restriction' : $c->version->counties->count().' counties',
                blocking: false,
                acknowledgeable: true,
                section: 'requirements',
                covers: ['version_counties.county_id'],
            ),
            self::dateItem(VersionDateType::Teacher, $phase, 'When can teachers register students?', 'Teachers can only register students inside this window; it also triggers the "access closing soon" reminders.'),
            self::dateItem(VersionDateType::Candidate, $phase, 'When can students use StudentFolder.info for this event?', 'Students can only register, upload, and pay inside this window.'),
            self::dateItem(
                VersionDateType::PostmarkDeadline,
                $phase,
                'When must mailed materials be postmarked?',
                'Teachers mail paperwork to complete registration; this is their deadline (also printed on PDF applications).',
                applicable: fn (ReadinessContext $c): bool => $c->mailRequired(),
            ),
            new ReadinessItem(
                key: 'version.dates.other',
                phase: $phase,
                question: 'Any other dates (admin access, final teacher changes, participation fee, rehearsal)?',
                why: 'Optional — set whichever apply so teachers see them on their dashboard.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => ReadinessStatus::NeedsReview,
                url: self::editTab('dates'),
                detail: fn (ReadinessContext $c): string => collect([VersionDateType::Admin, VersionDateType::FinalTeacherChanges, VersionDateType::ParticipationFee, VersionDateType::Rehearsal])
                    ->filter(fn (VersionDateType $t): bool => $c->dateIsSet($t))->count().' of 4 set',
                blocking: false,
                acknowledgeable: true,
                yearSensitive: true,
                section: 'dates',
                covers: ['version_dates.date_type', 'version_dates.start_at', 'version_dates.end_at'],
            ),
            new ReadinessItem(
                key: 'version.fees',
                phase: $phase,
                question: 'What do registration, participation, and housing cost?',
                why: 'Fees appear on estimate forms and drive online payments.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->version->fees !== null ? ReadinessStatus::NeedsReview : ReadinessStatus::NotStarted,
                url: self::editTab('fees'),
                detail: fn (ReadinessContext $c): ?string => $c->version->fees !== null ? 'Registration $'.number_format($c->version->fees->registrationInDollars(), 2) : null,
                acknowledgeable: true,
                yearSensitive: true,
                section: 'fees',
                covers: ['version_fees.registration', 'version_fees.on_site_registration', 'version_fees.participation', 'version_fees.epayment_surcharge', 'version_fees.housing'],
            ),
            new ReadinessItem(
                key: 'version.requirements.student_fields',
                phase: $phase,
                question: 'What do you need to know about each student (birthday, height, shirt size, home address)?',
                why: 'Teachers must collect whatever you require before a student can register.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => ReadinessStatus::NeedsReview,
                url: self::editTab('requirements'),
                blocking: false,
                acknowledgeable: true,
                section: 'requirements',
                covers: ['versions.birthday', 'versions.height', 'versions.shirt_size', 'versions.home_address'],
            ),
            new ReadinessItem(
                key: 'version.requirements.contacts',
                phase: $phase,
                question: 'What emergency-contact and teacher phone details are required?',
                why: 'Required contact details are enforced on every registration.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => ReadinessStatus::NeedsReview,
                url: self::editTab('requirements'),
                blocking: false,
                acknowledgeable: true,
                section: 'requirements',
                covers: ['versions.emergency_contact_name', 'versions.emergency_contact_cell', 'versions.emergency_contact_email', 'versions.teacher_cell'],
            ),
            new ReadinessItem(
                key: 'version.membership',
                phase: $phase,
                question: 'Must teachers hold a current membership card?',
                why: 'If required, teachers include their card with the estimate form.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => ReadinessStatus::NeedsReview,
                url: self::editTab('requirements'),
                blocking: false,
                acknowledgeable: true,
                yearSensitive: true,
                section: 'requirements',
                covers: ['version_membership_requirements.membership_card', 'version_membership_requirements.valid_thru'],
            ),
            new ReadinessItem(
                key: 'version.obligations',
                phase: $phase,
                question: 'What must teachers agree to before taking part?',
                why: 'Optional — when published, teachers must accept the obligations before they can register students.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => match (true) {
                    $c->version->obligation?->isPublished() === true => ReadinessStatus::Done,
                    $c->version->obligation !== null => ReadinessStatus::InProgress,
                    default => ReadinessStatus::NotStarted,
                },
                url: self::editTab('obligations'),
                detail: fn (ReadinessContext $c): ?string => $c->version->obligation !== null && ! $c->version->obligation->isPublished() ? 'Drafted, not published' : null,
                blocking: false,
                acknowledgeable: true,
                section: 'obligations',
                covers: ['version_obligations.title', 'version_obligations.body', 'version_obligations.status'],
            ),
            new ReadinessItem(
                key: 'version.invitations',
                phase: $phase,
                question: 'Which teachers are invited?',
                why: 'Only invited teachers can see the event and register students.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->invitedTeacherCount > 0 ? ReadinessStatus::Done : ReadinessStatus::NotStarted,
                url: fn (Version $v): string => route('events.versions.invitations', $v),
                detail: fn (ReadinessContext $c): string => $c->invitedTeacherCount.' '.str('teacher')->plural($c->invitedTeacherCount).' invited',
                yearSensitive: true,
                section: 'invitations',
                covers: ['version_invitations.teacher_id'],
            ),
        ];
    }

    /**
     * @return list<ReadinessItem>
     */
    private static function beforeRegistration(): array
    {
        $phase = ReadinessPhase::BeforeRegistration;

        return [
            new ReadinessItem(
                key: 'version.audition_format',
                phase: $phase,
                question: 'Do students audition in person or by recording, and is the application on paper or online?',
                why: 'These choices decide which of the remaining steps apply to you.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => ReadinessStatus::NeedsReview,
                url: self::editTab('general'),
                detail: fn (ReadinessContext $c): string => ($c->raw('audition_type') === AuditionType::InPerson->value ? 'In person' : 'Remote')
                    .', '.($c->isPdfApplication() ? 'PDF application' : 'online application')
                    .', uploads: '.$c->raw('upload_type'),
                blocking: false,
                acknowledgeable: true,
                section: 'general',
                covers: ['versions.audition_type', 'versions.audition_timeslot', 'versions.application_type', 'versions.upload_type'],
            ),
            new ReadinessItem(
                key: 'version.application',
                phase: $phase,
                question: 'What does the student application say?',
                why: 'Every registered student needs a signed application; it can\'t be produced until it\'s published.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => match (true) {
                    $c->version->candidateApplication?->isPublished() === true => ReadinessStatus::Done,
                    $c->version->candidateApplication !== null => ReadinessStatus::InProgress,
                    default => ReadinessStatus::NotStarted,
                },
                url: self::editTab('application'),
                detail: fn (ReadinessContext $c): ?string => $c->version->candidateApplication !== null && ! $c->version->candidateApplication->isPublished() ? 'Drafted, not published' : null,
                yearSensitive: true,
                section: 'application',
                covers: ['version_applications.student_endorsement_body', 'version_applications.parent_endorsement_body', 'version_applications.teacher_principal_endorsement_body', 'version_applications.schedule_body', 'version_applications.policies_body'],
            ),
            new ReadinessItem(
                key: 'version.upload_files',
                phase: $phase,
                question: 'What recordings must each student submit?',
                why: 'Students upload one recording per item listed here — with none, they have nothing to upload.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->version->uploadFiles->isNotEmpty() ? ReadinessStatus::Done : ReadinessStatus::NotStarted,
                url: self::editTab('general'),
                applicable: fn (ReadinessContext $c): bool => $c->raw('upload_type') !== UploadType::None->value,
                detail: fn (ReadinessContext $c): string => $c->version->uploadFiles->count().' '.str('recording')->plural($c->version->uploadFiles->count()),
                yearSensitive: true,
                section: 'general',
                covers: ['version_upload_files.name'],
            ),
            new ReadinessItem(
                key: 'version.pitch_files',
                phase: $phase,
                question: 'What practice tracks and pitch files do students get, and who can see them?',
                why: 'Optional — students and teachers download these to prepare.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->version->pitchFiles->isNotEmpty() ? ReadinessStatus::Done : ReadinessStatus::NotStarted,
                url: fn (Version $v): string => route('events.versions.pitch-files', $v),
                detail: fn (ReadinessContext $c): string => $c->version->pitchFiles->count().' '.str('file')->plural($c->version->pitchFiles->count()),
                blocking: false,
                acknowledgeable: true,
                yearSensitive: true,
                section: 'pitch_files',
                covers: ['version_pitch_files.url', 'versions.pitch_file_visibility'],
            ),
            new ReadinessItem(
                key: 'version.caps',
                phase: $phase,
                question: 'Is there a limit on registrants overall, per school, or for upper voices?',
                why: 'Optional — caps stop registration once reached.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => ReadinessStatus::NeedsReview,
                url: self::editTab('general'),
                blocking: false,
                acknowledgeable: true,
                section: 'general',
                covers: ['versions.max_registrants', 'versions.max_upper_voice_registrants', 'versions.audition_cap_per_school'],
            ),
            new ReadinessItem(
                key: 'version.epayment.decision',
                phase: $phase,
                question: 'How do teachers and students pay?',
                why: 'Teachers settle their balance online and/or by check; each teacher may also let their students pay online. Online payment needs a connected Square or PayPal account.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => ReadinessStatus::NeedsReview,
                url: self::editTab('payments'),
                detail: fn (ReadinessContext $c): string => self::paymentSummary($c),
                blocking: false,
                acknowledgeable: true,
                section: 'payments',
                covers: ['version_epayment_configs.epayment_student', 'version_epayment_configs.epayment_teacher', 'version_epayment_configs.online_payment_required'],
            ),
            new ReadinessItem(
                key: 'event.epayment.credentials',
                phase: $phase,
                question: 'Connect your Square or PayPal account',
                why: 'Online payments are turned on but can\'t be taken until an account is connected. Square setup must be done by the account owner.',
                // Presence check on the raw ciphertext — never decrypt here: a
                // credential written under another environment's APP_KEY throws
                // "The MAC is invalid" on read (local/prod keys differ by design).
                resolve: fn (ReadinessContext $c): ReadinessStatus => match (true) {
                    $c->epaymentConfig?->epaymentAccepted() === true && filled($c->epaymentConfig->getRawOriginal('secret')) => ReadinessStatus::Done,
                    $c->epaymentConfig?->epaymentAccepted() === true => ReadinessStatus::InProgress,
                    default => ReadinessStatus::NotStarted,
                },
                url: self::editTab('payments'),
                applicable: fn (ReadinessContext $c): bool => $c->epaymentEnabled(),
                section: 'payments',
                covers: ['event_epayment_configs.vendor', 'event_epayment_configs.vendor_account_id', 'event_epayment_configs.secret', 'event_epayment_configs.webhook_signature_key'],
            ),
            new ReadinessItem(
                key: 'version.roles.registration_manager',
                phase: $phase,
                question: 'Who runs registration?',
                why: 'The Registration Manager is the teachers\' contact and receives mailed paperwork.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->roleHolders('Registration Manager')->isNotEmpty() ? ReadinessStatus::Done : ReadinessStatus::NotStarted,
                url: self::editTab('roles'),
                detail: fn (ReadinessContext $c): ?string => $c->roleHolders('Registration Manager')->first()?->name,
                yearSensitive: true,
                section: 'roles',
                covers: ['model_has_roles.registration_manager'],
            ),
            new ReadinessItem(
                key: 'version.roles.mail_to',
                phase: $phase,
                question: 'Where do teachers mail their paperwork?',
                why: 'The mailing address is printed on every estimate form.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->registrationManagerHasMailTo ? ReadinessStatus::Done : ReadinessStatus::NotStarted,
                url: self::editTab('roles'),
                applicable: fn (ReadinessContext $c): bool => $c->mailRequired(),
                yearSensitive: true,
                section: 'roles',
                covers: ['version_mail_to_addresses.address_line1', 'versions.mail_required'],
            ),
            new ReadinessItem(
                key: 'version.roles.co_registration',
                phase: $phase,
                question: 'Will county co-managers share registration work?',
                why: 'Optional — Co-Registration Managers handle the schools in their counties.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->roleHolders('Co-Registration Manager')->isNotEmpty() ? ReadinessStatus::Done : ReadinessStatus::NeedsReview,
                url: fn (Version $v): string => route('events.versions.co-registration-managers', $v),
                blocking: false,
                acknowledgeable: true,
                yearSensitive: true,
                section: 'co_registration',
                covers: ['co_registration_manager_counties.county_id'],
                editor: ReadinessEditor::RegistrationManager,
            ),
        ];
    }

    /**
     * @return list<ReadinessItem>
     */
    private static function beforeAuditions(): array
    {
        $phase = ReadinessPhase::BeforeAuditions;

        return [
            self::dateItem(VersionDateType::Adjudication, $phase, 'When will judges score?', 'Judges can only reach the scoring page inside this window.'),
            self::dateItem(VersionDateType::TabRoom, $phase, 'When does the tab room run?', 'Tells your Tab Room team when results processing happens.'),
            new ReadinessItem(
                key: 'version.judge_count',
                phase: $phase,
                question: 'How many judges score each student?',
                why: 'Each room needs this many judges assigned before auditions.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => ReadinessStatus::NeedsReview,
                url: self::editTab('general'),
                detail: fn (ReadinessContext $c): string => $c->raw('judge_count').' per room',
                blocking: false,
                acknowledgeable: true,
                section: 'general',
                covers: ['versions.judge_count'],
            ),
            new ReadinessItem(
                key: 'version.rooms',
                phase: $phase,
                question: 'What audition rooms are there, and which voice parts does each hear?',
                why: 'Judges score by room — in person or remote, every student must land in a room.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->version->rooms->isNotEmpty() ? ReadinessStatus::Done : ReadinessStatus::NotStarted,
                url: fn (Version $v): string => route('events.versions.rooms', $v),
                detail: fn (ReadinessContext $c): string => $c->version->rooms->count().' '.str('room')->plural($c->version->rooms->count()),
                yearSensitive: true,
                section: 'rooms',
                covers: ['version_rooms.name', 'version_rooms.tolerance'],
                editor: ReadinessEditor::AuditionEnvironment,
            ),
            new ReadinessItem(
                key: 'version.room_judges',
                phase: $phase,
                question: 'Who judges in each room?',
                why: 'A room without its full panel of judges can\'t finish scoring.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => self::fraction(self::staffedRooms($c), $c->version->rooms->count()),
                url: fn (Version $v): string => route('events.versions.rooms', $v),
                applicable: fn (ReadinessContext $c): bool => $c->version->rooms->isNotEmpty(),
                detail: fn (ReadinessContext $c): string => self::staffedRooms($c).' of '.$c->version->rooms->count().' rooms fully staffed',
                yearSensitive: true,
                section: 'rooms',
                covers: ['room_judges.user_id'],
                editor: ReadinessEditor::AuditionEnvironment,
            ),
            new ReadinessItem(
                key: 'version.roles.tab_room',
                phase: $phase,
                question: 'Who runs the tab room?',
                why: 'The Tab Room Manager checks scores, sets cut-offs, and releases results.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->roleHolders('Tab Room Manager')->isNotEmpty() ? ReadinessStatus::Done : ReadinessStatus::NotStarted,
                url: self::editTab('roles'),
                yearSensitive: true,
                section: 'roles',
                covers: ['model_has_roles.tab_room_manager'],
            ),
        ];
    }

    /**
     * @return list<ReadinessItem>
     */
    private static function beforeResults(): array
    {
        $phase = ReadinessPhase::BeforeResults;

        return [
            new ReadinessItem(
                key: 'version.score_order',
                phase: $phase,
                question: 'Is a low total or a high total the better score?',
                why: 'Decides how students are ranked for cut-offs.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => ReadinessStatus::NeedsReview,
                url: self::editTab('general'),
                detail: fn (ReadinessContext $c): string => $c->raw('score_order') === ScoreOrder::Asc->value ? 'Lower is better' : 'Higher is better',
                blocking: false,
                acknowledgeable: true,
                section: 'general',
                covers: ['versions.score_order'],
            ),
            new ReadinessItem(
                key: 'version.cutoff_strategy',
                phase: $phase,
                question: 'How are students placed into ensembles?',
                why: 'The tab room can\'t set cut-offs until a placement method is chosen.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => $c->raw('cutoff_strategy') !== null ? ReadinessStatus::Done : ReadinessStatus::NotStarted,
                url: self::editTab('general'),
                section: 'general',
                covers: ['versions.cutoff_strategy'],
            ),
            new ReadinessItem(
                key: 'version.share_results',
                phase: $phase,
                question: 'Share anonymized results with all participating teachers?',
                why: 'If on, every teacher automatically gets the public results PDF when results are released.',
                resolve: fn (ReadinessContext $c): ReadinessStatus => ReadinessStatus::NeedsReview,
                url: self::editTab('general'),
                detail: fn (ReadinessContext $c): string => $c->version->share_results ? 'Sharing on' : 'Sharing off',
                blocking: false,
                acknowledgeable: true,
                section: 'general',
                covers: ['versions.share_results'],
            ),
        ];
    }

    /**
     * @param  (Closure(ReadinessContext): bool)|null  $applicable
     */
    private static function dateItem(VersionDateType $type, ReadinessPhase $phase, string $question, string $why, ?Closure $applicable = null): ReadinessItem
    {
        return new ReadinessItem(
            key: 'version.dates.'.$type->value,
            phase: $phase,
            question: $question,
            why: $why,
            resolve: function (ReadinessContext $c) use ($type): ReadinessStatus {
                if ($c->dateIsSet($type)) {
                    return ReadinessStatus::Done;
                }

                return $c->date($type) !== null ? ReadinessStatus::InProgress : ReadinessStatus::NotStarted;
            },
            url: self::editTab('dates'),
            applicable: $applicable,
            detail: fn (ReadinessContext $c): ?string => $c->startDate($type)?->format('M j, Y'),
            yearSensitive: true,
            section: 'dates',
            covers: ['version_dates.'.$type->value],
        );
    }

    private static function paymentSummary(ReadinessContext $c): string
    {
        $config = $c->version->versionEpaymentConfig;
        $teachers = TeacherPayments::fromFlags((bool) $config?->epayment_teacher, (bool) $config?->online_payment_required);

        $teacherText = match ($teachers) {
            TeacherPayments::CheckOnly => 'Teachers pay by check',
            TeacherPayments::OnlineOrCheck => 'Teachers pay online or by check',
            TeacherPayments::OnlineOnly => 'Teachers pay online only',
        };

        return $teacherText.'; '.($config?->epayment_student ? 'students may pay online (teacher\'s choice)' : 'students don\'t pay online');
    }

    private static function staffedRooms(ReadinessContext $c): int
    {
        $needed = max(1, (int) $c->raw('judge_count'));

        return $c->version->rooms->filter(fn (VersionRoom $room): bool => $room->roomJudges->count() >= $needed)->count();
    }

    private static function fraction(int $done, int $total): ReadinessStatus
    {
        return match (true) {
            $total > 0 && $done >= $total => ReadinessStatus::Done,
            $done > 0 => ReadinessStatus::InProgress,
            default => ReadinessStatus::NotStarted,
        };
    }

    /**
     * @return Closure(Version): string
     */
    private static function editTab(string $tab): Closure
    {
        return fn (Version $v): string => route('events.versions.edit', ['version' => $v, 'tab' => $tab]);
    }

    /**
     * @return Closure(Version): string
     */
    private static function ensemblesTab(): Closure
    {
        return fn (Version $v): string => route('events.show', ['event' => $v->event_id, 'tab' => 'ensembles']);
    }
}
