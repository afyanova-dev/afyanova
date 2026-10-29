<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * One amendment row in an operative note's history (ADR-152 D-G). A
 * protected read model — the ADR-136 deny-list applies to events/audit, not
 * to a gated query result for an authorized caller.
 */
final readonly class OperativeNoteAmendmentSummary
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
