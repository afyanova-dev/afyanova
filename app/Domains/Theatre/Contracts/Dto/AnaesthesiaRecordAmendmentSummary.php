<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * One amendment row in an anaesthesia record's history (ADR-155 D-F). A
 * protected read model.
 */
final readonly class AnaesthesiaRecordAmendmentSummary
{
    public function __construct(
        public string $amendmentId,
        public int $sequence,
        public string $amendmentText,
        public string $reason,
        public string $createdByUserId,
        public ?string $createdAt = null,
    ) {}
}
