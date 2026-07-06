<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AuditionType;
use App\Enums\CandidateStatus;
use App\Enums\EmergencyContactRelationship;
use App\Enums\EventStatus;
use App\Enums\Frequency;
use App\Enums\PhoneType;
use App\Enums\PitchFileVisibility;
use App\Enums\ScoreOrder;
use App\Enums\ShirtSize;
use App\Enums\Subject;
use App\Enums\TeacherRole;
use App\Enums\UploadType;
use App\Enums\VersionDateType;

test('ShirtSize has the expected cases and non-empty labels', function () {
    expect(array_map(fn (ShirtSize $case) => $case->value, ShirtSize::cases()))
        ->toBe(['xxs', 'xs', 'sm', 'med', 'lg', 'xl', 'xxl', 'xxxl', 'xxxxl']);

    foreach (ShirtSize::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('PhoneType has the expected cases and non-empty labels', function () {
    expect(array_map(fn (PhoneType $case) => $case->value, PhoneType::cases()))
        ->toBe(['cell', 'home', 'work']);

    foreach (PhoneType::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('Subject has the expected cases and non-empty labels', function () {
    expect(array_map(fn (Subject $case) => $case->value, Subject::cases()))
        ->toBe(['band', 'chorus', 'orchestra']);

    foreach (Subject::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('TeacherRole has the expected cases and non-empty labels', function () {
    expect(array_map(fn (TeacherRole $case) => $case->value, TeacherRole::cases()))
        ->toBe(['primary', 'coteacher']);

    foreach (TeacherRole::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('EmergencyContactRelationship has the expected cases and non-empty labels', function () {
    expect(array_map(fn (EmergencyContactRelationship $case) => $case->value, EmergencyContactRelationship::cases()))
        ->toBe([
            'mother',
            'father',
            'step_mother',
            'step_father',
            'grandmother',
            'grandfather',
            'guardian',
            'sibling',
            'aunt',
            'uncle',
            'guardian_mother',
            'guardian_father',
            'foster_mother',
            'foster_father',
            'other',
        ]);

    foreach (EmergencyContactRelationship::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('EventStatus has the expected cases and non-empty labels', function () {
    expect(array_map(fn (EventStatus $case) => $case->value, EventStatus::cases()))
        ->toBe(['sandbox', 'active', 'inactive', 'closed']);

    foreach (EventStatus::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('Frequency has the expected cases and non-empty labels', function () {
    expect(array_map(fn (Frequency $case) => $case->value, Frequency::cases()))
        ->toBe(['annual', 'biannual', 'biennial', 'monthly']);

    foreach (Frequency::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('ApplicationType has the expected cases and non-empty labels', function () {
    expect(array_map(fn (ApplicationType $case) => $case->value, ApplicationType::cases()))
        ->toBe(['eapplication', 'pdf']);

    foreach (ApplicationType::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('AuditionType has the expected cases and non-empty labels', function () {
    expect(array_map(fn (AuditionType $case) => $case->value, AuditionType::cases()))
        ->toBe(['in_person', 'remote']);

    foreach (AuditionType::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('PitchFileVisibility has the expected cases and non-empty labels', function () {
    expect(array_map(fn (PitchFileVisibility $case) => $case->value, PitchFileVisibility::cases()))
        ->toBe(['both', 'candidate', 'teacher']);

    foreach (PitchFileVisibility::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('ScoreOrder has the expected cases and non-empty labels', function () {
    expect(array_map(fn (ScoreOrder $case) => $case->value, ScoreOrder::cases()))
        ->toBe(['asc', 'desc']);

    foreach (ScoreOrder::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('UploadType has the expected cases and non-empty labels', function () {
    expect(array_map(fn (UploadType $case) => $case->value, UploadType::cases()))
        ->toBe(['audio', 'none', 'video']);

    foreach (UploadType::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('UploadType::requiresUpload is false only for None', function () {
    expect(UploadType::None->requiresUpload())->toBeFalse();
    expect(UploadType::Audio->requiresUpload())->toBeTrue();
    expect(UploadType::Video->requiresUpload())->toBeTrue();
});

test('VersionDateType has the expected cases and non-empty labels', function () {
    expect(array_map(fn (VersionDateType $case) => $case->value, VersionDateType::cases()))
        ->toBe([
            'admin', 'teacher', 'candidate', 'final_teacher_changes',
            'adjudication', 'tab_room', 'participation_fee', 'rehearsal', 'postmark_deadline',
        ]);

    foreach (VersionDateType::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('VersionDateType::hasEndAt is true only for date types that define a window', function () {
    expect(VersionDateType::Candidate->hasEndAt())->toBeTrue();
    expect(VersionDateType::Adjudication->hasEndAt())->toBeTrue();
    expect(VersionDateType::ParticipationFee->hasEndAt())->toBeTrue();
    expect(VersionDateType::Rehearsal->hasEndAt())->toBeTrue();

    expect(VersionDateType::Admin->hasEndAt())->toBeFalse();
    expect(VersionDateType::Teacher->hasEndAt())->toBeFalse();
    expect(VersionDateType::FinalTeacherChanges->hasEndAt())->toBeFalse();
    expect(VersionDateType::TabRoom->hasEndAt())->toBeFalse();
    expect(VersionDateType::PostmarkDeadline->hasEndAt())->toBeFalse();
});

test('CandidateStatus has the expected 12 cases and non-empty labels', function () {
    expect(array_map(fn (CandidateStatus $case) => $case->value, CandidateStatus::cases()))
        ->toBe([
            'eligible', 'pending', 'registered', 'withdrew', 'teacher_withdrawn',
            'adjudicated', 'no_show', 'incomplete', 'accepted', 'not_accepted', 'declined', 'removed',
        ]);

    foreach (CandidateStatus::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

test('CandidateStatus::registrationStates and isActive identify the pre-adjudication states', function () {
    expect(array_map(fn (CandidateStatus $case) => $case->value, CandidateStatus::registrationStates()))
        ->toBe(['eligible', 'pending', 'registered']);

    expect(CandidateStatus::Eligible->isActive())->toBeTrue();
    expect(CandidateStatus::Pending->isActive())->toBeTrue();
    expect(CandidateStatus::Registered->isActive())->toBeTrue();
    expect(CandidateStatus::Withdrew->isActive())->toBeFalse();
    expect(CandidateStatus::Accepted->isActive())->toBeFalse();
});
