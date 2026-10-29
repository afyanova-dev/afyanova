<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * An imaging study began but failed to complete (§56, §104, §177; ADR-166 D-H).
 *
 * PHI-safe: identifiers + the coded `outcomeStatusCode` only (ADR-136 deny-list
 * — the free-text `outcome_detail` is row-only + on the audit row, never here).
 */
final readonly class ImagingStudyAborted
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $imagingStudyId,
        public string $orderId,
        public string $accessionNumber,
        public string $outcomeStatusCode,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
