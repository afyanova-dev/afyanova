<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A specimen was rejected by the laboratory (14-laboratory-domain.md §34–§35,
 * §138; ADR-158 D-H). The free-text `rejection_detail` is row-only and is NOT
 * carried here — only the coded `reasonCode` (ADR-136 deny-list).
 */
final readonly class SpecimenRejected
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $specimenId,
        public string $orderId,
        public string $accessionNumber,
        public string $reasonCode,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
