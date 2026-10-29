<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/** One purchase-order amendment record (ADR-171 D-I / D-J). */
final readonly class PoAmendmentSummary
{
    public function __construct(
        public string $amendmentId,
        public int $sequence,
        public string $amendmentType,
        public ?string $poLineId,
        public string $fieldChanged,
        public ?string $previousValue,
        public ?string $newValue,
        public string $reason,
        public string $amendedByUserId,
        public string $amendedAt,
    ) {}
}
