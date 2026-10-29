<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Detailed staff record for HR administrative queries (25-hr-and-workforce-domain.md §6).
 */
final readonly class StaffDetail
{
    public function __construct(
        public string $staffId,
        public string $tenantId,
        public string $personId,
        public string $staffNumber,
        public string $status,
        public ?string $primaryCadreId,
        public ?string $primaryCadreName,
        public ?string $primaryPositionId,
        public ?string $primaryPositionTitle,
        public ?string $joinedDate,
        public ?string $terminatedDate,
        public ?string $terminationReason,
        public ?string $suspensionReason,
        public int $version,
    ) {}
}
