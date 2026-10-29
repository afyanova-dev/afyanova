<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Cadre classification summary (25-hr-and-workforce-domain.md §4).
 */
final readonly class CadreSummary
{
    public function __construct(
        public string $cadreId,
        public string $tenantId,
        public string $code,
        public string $name,
        public string $category,
        public ?string $description,
        public bool $isActive,
        public int $version,
    ) {}
}
