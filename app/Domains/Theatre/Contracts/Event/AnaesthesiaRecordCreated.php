<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Event;

/**
 * An anaesthesia record was originated as DRAFT (17-theatre-domain.md §210;
 * ADR-155 D-G).
 *
 * PHI-safe: identifiers + the coded anaesthesia type only (ADR-136 deny-list
 * — record content NEVER carried).
 */
final readonly class AnaesthesiaRecordCreated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $anaesthesiaRecordId,
        public string $caseId,
        public string $patientId,
        public string $encounterId,
        public string $anaesthesiaType,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
