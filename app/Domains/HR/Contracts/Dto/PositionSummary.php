<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Job position summary (25-hr-and-workforce-domain.md §4).
 */
final readonly class PositionSummary
{
    public function __construct(
        public string $positionId,
        public string $tenantId,
        public string $code,
        public string $title,
        public string $cadreId,
        public ?string $cadreName,
        public ?string $cadreCategory,
        public ?string $description,
        public bool $isActive,
        public int $version,
    ) {}
}
