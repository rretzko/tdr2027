<?php

declare(strict_types=1);

namespace App\Support;

final class ClassOfCalculator
{
    /**
     * Compute a student's graduating class year from their current grade and the school's senior year.
     */
    public static function classOfFromGrade(int $grade, int $seniorYear): int
    {
        return $seniorYear + (12 - $grade);
    }

    /**
     * Compute a student's current grade from their graduating class year and the school's senior year.
     */
    public static function gradeFromClassOf(int $classOf, int $seniorYear): int
    {
        return 12 - ($classOf - $seniorYear);
    }
}
