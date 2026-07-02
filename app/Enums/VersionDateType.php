<?php

declare(strict_types=1);

namespace App\Enums;

enum VersionDateType: string
{
    case Admin = 'admin';
    case Teacher = 'teacher';
    case Candidate = 'candidate';
    case FinalTeacherChanges = 'final_teacher_changes';
    case Adjudication = 'adjudication';
    case TabRoom = 'tab_room';
    case ParticipationFee = 'participation_fee';
    case Rehearsal = 'rehearsal';
    case PostmarkDeadline = 'postmark_deadline';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin Access',
            self::Teacher => 'Teacher Access',
            self::Candidate => 'Candidate Access',
            self::FinalTeacherChanges => 'Final Teacher Changes',
            self::Adjudication => 'Adjudication',
            self::TabRoom => 'Tab Room',
            self::ParticipationFee => 'Participation Fee Payment',
            self::Rehearsal => 'Rehearsal',
            self::PostmarkDeadline => 'Postmark Deadline',
        };
    }

    public function hasEndAt(): bool
    {
        return in_array($this, [
            self::Candidate,
            self::Adjudication,
            self::ParticipationFee,
            self::Rehearsal,
        ], true);
    }
}
