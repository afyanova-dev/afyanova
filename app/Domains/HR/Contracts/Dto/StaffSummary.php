<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Operational staff summary for external consumers (25-hr-and-workforce-domain.md §6; ADR-206).
 *
 * Exposes strictly verified operational facts with zero PII leakage
 * (no national ID, DOB, contacts, or next-of-kin).
 */
final readonly class StaffSummary
{
    public function __construct(
        public string $staffId,
        public string $staffNumber,
        public string $displayName,
        public ?string $cadreCategory,
        public ?string $cadreName,
        public ?string $positionTitle,
        public string $status,
        public bool $isActive,
    ) {}
}
