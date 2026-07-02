<?php

declare(strict_types=1);

namespace App\Enums;

enum UploadType: string
{
    case Audio = 'audio';
    case None = 'none';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Audio => 'Audio',
            self::None => 'None',
            self::Video => 'Video',
        };
    }

    public function requiresUpload(): bool
    {
        return $this !== self::None;
    }
}
