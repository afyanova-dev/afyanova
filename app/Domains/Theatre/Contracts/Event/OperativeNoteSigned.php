<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * An operative note was signed — content is now frozen
 * (17-theatre-domain.md §80, §210, §303 Invariant 30; ADR-152 D-H).
 * PHI-safe: identifiers only.
 */
final readonly class OperativeNoteSigned
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $operativeNoteId,
        public string $caseId,
        public string $signedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
