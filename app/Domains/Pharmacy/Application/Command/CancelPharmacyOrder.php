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
 * Administratively cancel a Pharmacy Order — any non-terminal state → CANCELLED
 * (16-pharmacy-domain.md §58; ADR-182 D-D). Terminal.
 *
 * `reasonCode` SET-validated (§58); `note` ROW-ONLY. This is Pharmacy's
 * administrative cancellation of the fulfilment task — a prescription CANCELLED
 * in Pharm.1 does NOT auto-cancel its order (ADR-182 D-G).
 */
final readonly class CancelPharmacyOrder implements Auditable, AuthorizesAgainst
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
            throw new InvalidArgumentException('CancelPharmacyOrder ids must be UUIDs (IC §3).');
        }

        $this->reasonCode = PharmacyOrderReason::assertCancel($reasonCode);
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
            action: 'pharmacy.pharmacy_order_cancelled',
            actorId: $this->actorId,
            targetType: 'pharmacy_order',
            targetId: $this->pharmacyOrderId,
            reason: $this->reasonCode,
        );
    }
}
