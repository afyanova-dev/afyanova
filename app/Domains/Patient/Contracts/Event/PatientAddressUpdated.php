<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A patient address was created, revised or deactivated (10-patient-domain.md
 * §33-§34, §132; ADR-126). One event covers all three — §132 lists a single
 * PatientAddressUpdated.
 *
 * PHI-free (IC §7.1.1): no street / region / district / postal data — only
 * ids, the coded type, and whether the address is now active.
 */
final readonly class PatientAddressUpdated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $patientId,
        public string $addressId,
        public string $addressType,
        public bool $isActive,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
