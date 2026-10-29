<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Release a held Pharmacy Order — ON_HOLD → RECEIVED
 * (16-pharmacy-domain.md §123; ADR-182 D-D). Returns to the start of the
 * validation workflow (must re-validate — conservative).
 */
final readonly class ReleasePharmacyOrderHold implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $pharmacyOrderId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($pharmacyOrderId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReleasePharmacyOrderHold ids must be UUIDs (IC §3).');
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
            action: 'pharmacy.pharmacy_order_hold_released',
            actorId: $this->actorId,
            targetType: 'pharmacy_order',
            targetId: $this->pharmacyOrderId,
        );
    }
}
