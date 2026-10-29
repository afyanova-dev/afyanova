<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/** One append-only quotation-revision record (§233; ADR-173 D-F). */
final readonly class QuotationRevisionSummary
{
    public function __construct(
        public string $revisionId,
        public int $sequence,
        public string $fieldChanged,
        public ?string $previousValue,
        public ?string $newValue,
        public string $reason,
        public string $revisedByUserId,
        public string $revisedAt,
    ) {}
}
