<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One append-only contract-amendment record (ADR-178 D-F). Returned on the
 * coarse `procurement.access` gate as part of `ContractSummary.amendments`.
 * `reason` is free text — visible to an authorised reader, never in an event.
 */
final readonly class ContractAmendmentSummary
{
    public function __construct(
        public int $sequence,
        public string $amendmentType,
        public ?string $contractLineId,
        public string $fieldChanged,
        public ?string $previousValue,
        public ?string $newValue,
        public string $reason,
        public string $amendedByUserId,
        public string $amendedAt,
    ) {}
}
