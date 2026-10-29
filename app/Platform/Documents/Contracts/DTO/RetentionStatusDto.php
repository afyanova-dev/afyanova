<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class RetentionStatusDto
{
    public function __construct(
        public string $documentId,
        public bool $isUnderLegalHold,
        public bool $isRetained,
        public bool $canBeDisposed,
        public int $activeRequirementsCount,
        public ?CarbonImmutable $earliestDisposalDate = null,
    ) {}
}
