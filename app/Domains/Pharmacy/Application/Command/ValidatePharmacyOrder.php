<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A pharmacist validates a Pharmacy Order — RECEIVED → VALIDATED
 * (16-pharmacy-domain.md §106; ADR-182 D-D).
 *
 * A pharmacist attestation that the prescription is clinically sound and
 * cleared for fulfilment. Pharm.2a runs NO automated safety checks (no catalog,
 * no allergy / interaction engine — those are later increments).
 */
final readonly class ValidatePharmacyOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $pharmacyOrderId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($pharmacyOrderId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ValidatePharmacyOrder ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->pharmacyOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.pharmacy_order_validated',
            actorId: $this->actorId,
            targetType: 'pharmacy_order',
            targetId: $this->pharmacyOrderId,
        );
    }
}
