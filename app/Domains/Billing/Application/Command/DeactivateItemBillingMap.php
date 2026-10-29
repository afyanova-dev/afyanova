<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Deactivate the active `inv_items.id → billing_service` mapping for an item
 * (ADR-189 D-A). Class-level `manage` (`billing.catalog.manage`). A subsequent
 * `MedicationDispensed` for the item then captures a PENDING / ITEM_UNMAPPED
 * billable event (Bill.3b).
 */
final readonly class DeactivateItemBillingMap implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $actorId,
        public string $invItemId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($invItemId)) {
            throw new InvalidArgumentException('DeactivateItemBillingMap ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.item_mapping_deactivated',
            actorId: $this->actorId,
            targetType: 'item_billing_map',
            targetId: $this->invItemId,
        );
    }
}
