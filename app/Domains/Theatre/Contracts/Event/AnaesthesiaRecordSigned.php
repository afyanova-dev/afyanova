<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * An anaesthesia record was signed — content is now frozen
 * (17-theatre-domain.md §210; ADR-155 D-G). PHI-safe: identifiers only.
 */
final readonly class AnaesthesiaRecordSigned
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $anaesthesiaRecordId,
        public string $caseId,
        public string $signedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
