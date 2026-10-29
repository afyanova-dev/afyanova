<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * An operative note passed review (17-theatre-domain.md §79, §210;
 * ADR-152 D-H). PHI-safe: identifiers only.
 */
final readonly class OperativeNoteReviewed
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $operativeNoteId,
        public string $caseId,
        public string $reviewedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
