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
 * Park a Pharmacy Order — RECEIVED | VALIDATED → ON_HOLD
 * (16-pharmacy-domain.md §123; ADR-182 D-D). Releasing it returns to RECEIVED
 * (must re-validate). `reasonCode` SET-validated (§123); `note` ROW-ONLY.
 */
final readonly class HoldPharmacyOrder implements Auditable, AuthorizesAgainst
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
            throw new InvalidArgumentException('HoldPharmacyOrder ids must be UUIDs (IC §3).');
        }

        $this->reasonCode = PharmacyOrderReason::assertHold($reasonCode);
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
            action: 'pharmacy.pharmacy_order_held',
            actorId: $this->actorId,
            targetType: 'pharmacy_order',
            targetId: $this->pharmacyOrderId,
            reason: $this->reasonCode,
        );
    }
}
