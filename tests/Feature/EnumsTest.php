<?php

declare(strict_types=1);

use App\Enums\EmergencyContactRelationship;
use App\Enums\PhoneType;
use App\Enums\ShirtSize;
use App\Enums\Subject;
use App\Enums\TeacherRole;

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
