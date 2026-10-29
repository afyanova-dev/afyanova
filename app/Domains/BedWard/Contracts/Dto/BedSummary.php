<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Dto;

/**
 * Minimal summary read of one bed.
 */
final readonly class BedSummary
{
    /**
     * @param  list<string>|array<string, mixed>  $features
     */
    public function __construct(
        public string $bedId,
        public string $facilityId,
        public string $wardId,
        public ?string $roomId,
        public string $label,
        public string $bedClass,
        public array $features,
        public string $status,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
