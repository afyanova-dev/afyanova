<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A recovery handoff was acknowledged by the receiving side
 * (17-theatre-domain.md §210; ADR-156 D-D). PHI-safe: identifiers only.
 */
final readonly class RecoveryHandoffAcknowledged
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $recoveryHandoffId,
        public string $caseId,
        public string $acknowledgedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
