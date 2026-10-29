<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * An amendment was appended to a signed operative note
 * (17-theatre-domain.md §81, §210, §303 Invariant 32; ADR-152 D-H). The
 * amendment text and reason are row/audit-only (ADR-136 deny-list), never
 * carried here. PHI-safe: identifiers only.
 */
final readonly class OperativeNoteAmended
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $operativeNoteId,
        public string $caseId,
        public string $amendmentId,
        public int $sequence,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
