<?php

declare(strict_types=1);

namespace App\Enums\JudgeSurvey;

/**
 * The three points in a judge's roster where the Adjudicate page pauses to
 * congratulate them and ask a short, dismissible survey. Messages praise
 * progress and care, never speed: praising speed would push judges to rush.
 *
 * Placeholders: {name}, {done}, {total}, {remaining}, {room}.
 */
enum JudgeCheckpoint: string
{
    case First = 'first';
    case Second = 'second';
    case Final = 'final';

    /** Below this many candidates the ⅓ and ⅔ checkpoints are too close together, so ⅔ is skipped. */
    public const SMALL_ROOM = 6;

    /**
     * Fully-scored candidates at which each checkpoint is due, for a roster
     * of $total. A checkpoint whose count matches a later one's is dropped
     * (the later one wins), as is one at 0 or at the whole roster: finishing
     * the roster gets a thank-you instead of a survey.
     *
     * @return array<string, int> checkpoint value => completed count
     */
    public static function thresholds(int $total): array
    {
        $raw = [
            self::First->value => (int) ceil($total / 3),
            self::Second->value => $total < self::SMALL_ROOM ? 0 : (int) ceil($total * 2 / 3),
            self::Final->value => $total - 1,
        ];

        $thresholds = [];
        $taken = [];

        foreach (array_reverse($raw, true) as $checkpoint => $count) {
            if ($count < 1 || $count >= $total || in_array($count, $taken, true)) {
                continue;
            }

            $thresholds[$checkpoint] = $count;
            $taken[] = $count;
        }

        return array_reverse($thresholds, true);
    }

    /** The checkpoint whose count this save just reached, if any. */
    public static function reachedAt(int $completed, int $total): ?self
    {
        $checkpoint = array_search($completed, self::thresholds($total), true);

        return $checkpoint === false ? null : self::from($checkpoint);
    }

    public function label(): string
    {
        return match ($this) {
            self::First => 'One-third',
            self::Second => 'Two-thirds',
            self::Final => 'One to go',
        };
    }

    public function heading(): string
    {
        return match ($this) {
            self::First => 'End of the exposition',
            self::Second => 'Into the development',
            self::Final => 'The final cadence',
        };
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return match ($this) {
            self::First => [
                'Nice work, {name}. {done} of {total} heard. That\'s the first theme down.',
                'A third of the way there, {name}. Your careful listening is exactly what these students are counting on.',
                '{done} of {total} done, {name}. Every student so far has had your full attention, and it shows.',
            ],
            self::Second => [
                'Two-thirds done, {name}. The recapitulation is in sight.',
                '{remaining} to go, {name}. You\'re through the hard middle stretch.',
                '{done} of {total}, {name}. Thank you for staying with every phrase.',
            ],
            self::Final => [
                'One more, {name}. Bring it home.',
                'Last one, {name}. Thank you for giving every student a fair hearing.',
                'One candidate left in {room}, {name}. Give it the same ears you gave the first.',
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function tips(): array
    {
        return match ($this) {
            self::First => [
                'Good moment to stand up, stretch and get some water. Your ears will thank you.',
                'A minute away from the screen now helps your last candidates as much as your first.',
            ],
            self::Second => [
                'Ears get tired before you notice. Take two minutes of silence, then check your volume against your first recordings.',
                'Before you continue, it can help to replay one of your early recordings to re-anchor your scoring.',
            ],
            self::Final => [
                'Take a breath before you press play.',
            ],
        };
    }

    public static function completionMessage(): string
    {
        return 'That\'s every candidate in {room}. Thank you, {name}. This event runs on judges like you.';
    }

    /**
     * @param  array<string, string|int>  $values
     */
    public static function fill(string $template, array $values): string
    {
        return strtr($template, collect($values)->mapWithKeys(fn (string|int $value, string $key): array => ['{'.$key.'}' => (string) $value])->all());
    }
}
