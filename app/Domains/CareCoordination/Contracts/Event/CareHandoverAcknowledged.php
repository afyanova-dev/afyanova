<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * The receiving team acknowledged ongoing responsibility for a handover
 * referral (26-care-coordination-domain.md §11, §9; ADR-151 D-J). PHI-safe:
 * identifiers only.
 */
final readonly class CareHandoverAcknowledged
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $referralId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
