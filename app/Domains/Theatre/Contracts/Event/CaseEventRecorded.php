<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * An intra-operative case event was recorded (17-theatre-domain.md §210;
 * ADR-156 D-E). PHI-safe: identifiers + coded event type + timestamp only
 * (the note is NEVER carried — ADR-136 deny-list).
 */
final readonly class CaseEventRecorded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $caseEventId,
        public string $caseId,
        public string $eventType,
        public string $eventAt,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
