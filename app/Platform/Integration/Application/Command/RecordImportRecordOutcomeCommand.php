<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

final readonly class RecordImportRecordOutcomeCommand
{
    public function __construct(
        public string $fileImportId,
        public int $recordIndex,
        public bool $committed,
        public ?string $reason = null,
    ) {}
}
