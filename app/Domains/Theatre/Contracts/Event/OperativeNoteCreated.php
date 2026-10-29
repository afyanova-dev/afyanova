<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * An operative note was originated as DRAFT (17-theatre-domain.md §210;
 * ADR-152 D-H).
 *
 * PHI-safe: identifiers only (ADR-136 deny-list — note content NEVER
 * carried).
 */
final readonly class OperativeNoteCreated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $operativeNoteId,
        public string $caseId,
        public string $patientId,
        public string $encounterId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
