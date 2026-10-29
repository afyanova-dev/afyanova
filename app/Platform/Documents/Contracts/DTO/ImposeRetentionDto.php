<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\DTO;

use App\Platform\Documents\Domain\Enum\RetentionImposedBy;
use Carbon\CarbonImmutable;

final readonly class ImposeRetentionDto
{
    public function __construct(
        public string $tenantId,
        public string $documentId,
        public RetentionImposedBy $imposedBy,
        public string $reason,
        public string $actorId,
        public ?CarbonImmutable $retainUntil = null,
        public bool $indefinite = false,
    ) {}
}
