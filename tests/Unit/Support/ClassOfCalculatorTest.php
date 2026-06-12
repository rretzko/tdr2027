<?php

declare(strict_types=1);

use App\Support\ClassOfCalculator;

test('classOfFromGrade computes the graduating year from grade and senior year', function () {
    expect(ClassOfCalculator::classOfFromGrade(12, 2026))->toBe(2026);
    expect(ClassOfCalculator::classOfFromGrade(0, 2026))->toBe(2038);
    expect(ClassOfCalculator::classOfFromGrade(9, 2026))->toBe(2029);
});

test('gradeFromClassOf computes the current grade from class_of and senior year', function () {
    expect(ClassOfCalculator::gradeFromClassOf(2026, 2026))->toBe(12);
    expect(ClassOfCalculator::gradeFromClassOf(2038, 2026))->toBe(0);
    expect(ClassOfCalculator::gradeFromClassOf(2029, 2026))->toBe(9);
});

test('the two calculators are inverses of each other', function () {
    foreach (range(0, 12) as $grade) {
        $classOf = ClassOfCalculator::classOfFromGrade($grade, 2026);

        expect(ClassOfCalculator::gradeFromClassOf($classOf, 2026))->toBe($grade);
    }
});
