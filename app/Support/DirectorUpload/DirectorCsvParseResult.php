<?php

declare(strict_types=1);

namespace App\Support\DirectorUpload;

final readonly class DirectorCsvParseResult
{
    /**
     * @param  list<DirectorUploadRow>  $rows
     * @param  list<string>  $fileErrors  file-level problems (missing headers, too many rows) — no rows are usable
     * @param  list<string>  $notices  informational (ignored columns, skipped example rows)
     */
    public function __construct(
        public array $rows,
        public array $fileErrors,
        public array $notices,
    ) {}
}
