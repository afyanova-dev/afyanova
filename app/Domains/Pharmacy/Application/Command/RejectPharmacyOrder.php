<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderReason;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A pharmacist rejects a Pharmacy Order — RECEIVED | VALIDATED → REJECTED
 * (16-pharmacy-domain.md §124; ADR-182 D-D). Terminal.
 *
 * `reasonCode` is SET-validated (§124). `note` is the free-text detail — ROW-ONLY
 * (the ADR-136 deny-list): carried on the dedicated `AuditEntry` `reason:` column
 * as the CODED value; the free text is never in audit metadata or the event.
 */
final readonly class RejectPharmacyOrder implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $pharmacyOrderId,
        public string $actorId,
        string $reasonCode,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($pharmacyOrderId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RejectPharmacyOrder ids must be UUIDs (IC §3).');
        }

        $this->reasonCode = PharmacyOrderReason::assertReject($reasonCode);
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
            action: 'pharmacy.pharmacy_order_rejected',
            actorId: $this->actorId,
            targetType: 'pharmacy_order',
            targetId: $this->pharmacyOrderId,
            reason: $this->reasonCode,
        );
    }
}
