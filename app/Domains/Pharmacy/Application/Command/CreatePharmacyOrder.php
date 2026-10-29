<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Open a Pharmacy Order for an `ACTIVE` prescription
 * (16-pharmacy-domain.md §21, §22; ADR-182 D-D).
 *
 * The operational fulfilment task. Class-level authorization (`create`) — the
 * handler adds the ADR-133 per-facility refinement at the prescription's
 * ORIGINATING facility. One non-terminal order per prescription (the partial
 * unique index; `PharmacyOrderAlreadyExists` on the race).
 *
 * `priority` is open-coded (ROUTINE | URGENT | STAT), behaviour-neutral.
 * `dispensingLocationId`, when given, is an opaque `inv_stock_locations` id —
 * NOT validated in Pharm.2a (Pharm.3's reserve path resolves it).
 */
final readonly class CreatePharmacyOrder implements Auditable, AuthorizesAgainst
{
    private const PRIORITIES = ['ROUTINE', 'URGENT', 'STAT'];

    public string $priority;

    public function __construct(
        public string $pharmacyOrderId,
        public string $actorId,
        public string $prescriptionId,
        public ?string $dispensingLocationId = null,
        string $priority = 'ROUTINE',
        public ?string $idempotencyKey = null,
    ) {
        foreach (['pharmacyOrderId' => $pharmacyOrderId, 'actorId' => $actorId, 'prescriptionId' => $prescriptionId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreatePharmacyOrder::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($dispensingLocationId !== null && ! Str::isUuid($dispensingLocationId)) {
            throw new InvalidArgumentException('CreatePharmacyOrder::$dispensingLocationId must be a UUID or null (IC §3).');
        }

        $priority = strtoupper(trim($priority));
        if (! in_array($priority, self::PRIORITIES, true)) {
            throw new InvalidArgumentException('CreatePharmacyOrder::$priority must be ROUTINE | URGENT | STAT (§22).');
        }

        $this->priority = $priority;
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.pharmacy_order_created',
            actorId: $this->actorId,
            targetType: 'pharmacy_order',
            targetId: $this->pharmacyOrderId,
            metadata: [
                'prescription_id' => $this->prescriptionId,
                'priority' => $this->priority,
            ],
        );
    }
}
