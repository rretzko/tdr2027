<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\VersionObligation;
use Mews\Purifier\Facades\Purifier;

class VersionObligationObserver
{
    public function saving(VersionObligation $obligation): void
    {
        if (! $obligation->isDirty('body')) {
            return;
        }

        $obligation->body = Purifier::clean($obligation->body, 'obligations');
    }
}
