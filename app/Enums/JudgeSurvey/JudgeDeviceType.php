<?php

declare(strict_types=1);

namespace App\Enums\JudgeSurvey;

/**
 * The screen a judge is scoring on, detected from the browser rather than
 * asked: a touch screen under 600px on its short side is a phone, under
 * 1100px a tablet, anything else (or any screen driven by a mouse or trackpad, including touch laptops) a computer.
 */
enum JudgeDeviceType: string
{
    case Phone = 'phone';
    case Tablet = 'tablet';
    case Computer = 'computer';

    public static function detect(int $screenWidth, int $screenHeight, bool $coarsePointer): self
    {
        $shortSide = min($screenWidth, $screenHeight);

        return match (true) {
            ! $coarsePointer => self::Computer,
            $shortSide < 600 => self::Phone,
            $shortSide < 1100 => self::Tablet,
            default => self::Computer,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Tablet => 'Tablet',
            self::Computer => 'Computer',
        };
    }
}
