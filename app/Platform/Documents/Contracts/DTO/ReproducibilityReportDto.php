<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class ReproducibilityReportDto
{
    public function __construct(
        public string $versionId,
        public string $documentId,
        public bool $isReproducible,
        public string $storedHash,
        public string $recomputedHash,
        public string $generationContextHash,
        public CarbonImmutable $evaluatedAt,
    ) {}
}
