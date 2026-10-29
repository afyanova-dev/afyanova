<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * A recovery handoff was initiated (17-theatre-domain.md §210; ADR-156 D-D).
 * PHI-safe: identifiers + coded destination only (condition / pending actions
 * NEVER carried — ADR-136 deny-list).
 */
final readonly class RecoveryHandoffInitiated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $recoveryHandoffId,
        public string $caseId,
        public string $patientId,
        public string $encounterId,
        public string $destinationType,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
