<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * One row of the live OR-board (ADR-150 D-J — the Ward Census precedent).
 * Backend data only in T.1 (no OR-board UI — ADR-150 D-L).
 */
final readonly class OrBoardEntry
{
    public function __construct(
        public string $roomId,
        public string $label,
        public string $roomStatus,
        public ?string $currentCaseId,
        public ?string $currentCaseNumber,
        public ?string $currentCaseStatus,
        public ?string $currentCasePriority,
    ) {}
}
