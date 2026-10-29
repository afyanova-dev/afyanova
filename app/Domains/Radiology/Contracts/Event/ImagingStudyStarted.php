<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * An imaging study acquisition started (§30, §104, §177; ADR-166 D-H).
 *
 * PHI-safe: identifiers only (ADR-136 deny-list).
 */
final readonly class ImagingStudyStarted
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $imagingStudyId,
        public string $orderId,
        public string $accessionNumber,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
