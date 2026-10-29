<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Dto;

/**
 * Minimal summary read of one ward.
 */
final readonly class WardSummary
{
    public function __construct(
        public string $wardId,
        public string $facilityId,
        public string $code,
        public string $name,
        public string $wardType,
        public ?string $capabilityLink,
        public int $capacity,
        public string $status,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
