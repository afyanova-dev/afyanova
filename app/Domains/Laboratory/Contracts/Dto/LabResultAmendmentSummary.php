<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * One entry in a laboratory result's correction / cancellation history
 * (§137; ADR-160 D-G). A protected read model for an authorized caller.
 */
final readonly class LabResultAmendmentSummary
{
    public function __construct(
        public string $amendmentId,
        public string $resultId,
        public int $sequence,
        public string $amendmentType,
        public string $previousStatus,
        public string $newStatus,
        public ?string $previousValueType,
        public ?string $previousValueNumeric,
        public ?string $previousValueCode,
        public ?string $previousValueText,
        public ?string $newValueType,
        public ?string $newValueNumeric,
        public ?string $newValueCode,
        public ?string $newValueText,
        public string $reason,
        public ?string $replacementResultId,
        public string $amendedByUserId,
        public ?string $amendedAt,
    ) {}
}
