<?php

declare(strict_types=1);

use App\Support\EmailVerifiabilityChecker;

test('emails are likely unverifiable on k12, student, school, and *sd* domains', function (string $email) {
    expect(EmailVerifiabilityChecker::isLikelyUnverifiable($email))->toBeTrue();
})->with([
    'student@classroom.k12.nj.us',
    'student@mail.school.example.org',
    'student@my.student.example.org',
    'student@lincoln.regionalsd.org',
]);

test('emails are likely unverifiable on studentfolder.info', function () {
    expect(EmailVerifiabilityChecker::isLikelyUnverifiable('student@studentfolder.info'))->toBeTrue();
    expect(EmailVerifiabilityChecker::isLikelyUnverifiable('student@accounts.studentfolder.info'))->toBeTrue();
});

test('ordinary personal emails are verifiable', function (string $email) {
    expect(EmailVerifiabilityChecker::isLikelyUnverifiable($email))->toBeFalse();
})->with([
    'person@gmail.com',
    'person@example.com',
    'person@thedirectorsroom.com',
]);

test('matching is case-insensitive', function () {
    expect(EmailVerifiabilityChecker::isLikelyUnverifiable('Student@Classroom.K12.NJ.US'))->toBeTrue();
});
