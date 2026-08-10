<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\CutoffStrategies\AlternatingEnsembleAssignmentStrategy;
use App\Services\CutoffStrategies\CutoffStrategyContract;
use App\Services\CutoffStrategies\GradeSegmentedEnsemblesStrategy;
use App\Services\CutoffStrategies\PerVoicePartPerEnsembleStrategy;
use App\Services\CutoffStrategies\SequentialEnsembleFillStrategy;

/**
 * The four Ensemble Cut-off assignment behaviors (Tab Room Module.docx §
 * "Ensemble Cut Off(s)"; see docs/plans/event-version-orientation.md §7.5
 * for the mapping this enum implements against). Configured once per
 * Version during setup (VersionEdit.php), not chosen ad hoc on the
 * Ensemble Cut-offs page.
 */
enum CutoffStrategy: string
{
    case PerVoicePartPerEnsemble = 'per_voice_part_per_ensemble';
    case SequentialEnsembleFill = 'sequential_ensemble_fill';
    case AlternatingEnsembleAssignment = 'alternating_ensemble_assignment';
    case GradeSegmentedEnsembles = 'grade_segmented_ensembles';

    public function label(): string
    {
        return match ($this) {
            self::PerVoicePartPerEnsemble => 'Single ensemble',
            self::SequentialEnsembleFill => 'Multiple ensembles, cascading cutoffs',
            self::AlternatingEnsembleAssignment => 'Multiple ensembles, alternating cutoff',
            self::GradeSegmentedEnsembles => 'Multiple ensembles defined by grade',
        };
    }

    /**
     * Whether this strategy needs one cutoff score per eligible Ensemble
     * (entered separately, one at a time) rather than a single shared score
     * applied to every eligible Ensemble at once.
     */
    public function needsPerEnsembleCutoff(): bool
    {
        return match ($this) {
            self::SequentialEnsembleFill, self::GradeSegmentedEnsembles => true,
            self::PerVoicePartPerEnsemble, self::AlternatingEnsembleAssignment => false,
        };
    }

    /**
     * @return class-string<CutoffStrategyContract>
     */
    public function strategyClass(): string
    {
        return match ($this) {
            self::PerVoicePartPerEnsemble => PerVoicePartPerEnsembleStrategy::class,
            self::SequentialEnsembleFill => SequentialEnsembleFillStrategy::class,
            self::AlternatingEnsembleAssignment => AlternatingEnsembleAssignmentStrategy::class,
            self::GradeSegmentedEnsembles => GradeSegmentedEnsemblesStrategy::class,
        };
    }
}
