<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * An amendment was appended to a signed anaesthesia record
 * (17-theatre-domain.md §210; ADR-155 D-G). The amendment text and reason are
 * row/audit-only (ADR-136 deny-list), never carried here. PHI-safe.
 */
final readonly class AnaesthesiaRecordAmended
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $anaesthesiaRecordId,
        public string $caseId,
        public string $amendmentId,
        public int $sequence,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
