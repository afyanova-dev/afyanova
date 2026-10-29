<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * Minimal summary read of one operating room (ADR-150 D-J).
 */
final readonly class OperatingRoomSummary
{
    public function __construct(
        public string $roomId,
        public string $facilityId,
        public string $orgRoomId,
        public string $label,
        public string $status,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
