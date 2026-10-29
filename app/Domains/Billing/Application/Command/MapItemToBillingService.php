<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Map an `inv_items.id` to a billing service (20-billing-and-payments-domain.md
 * §237; ADR-189 D-A). Class-level `manage` (`billing.catalog.manage` — REUSED;
 * the map is pricing configuration).
 *
 * The billing service must exist + be ACTIVE. Supersedes any active mapping for
 * the item.
 */
final readonly class MapItemToBillingService implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $mapId,
        public string $actorId,
        public string $invItemId,
        public string $billingServiceId,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'mapId' => $mapId, 'actorId' => $actorId,
            'invItemId' => $invItemId, 'billingServiceId' => $billingServiceId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("MapItemToBillingService::\${$name} must be a UUID (IC §3).");
            }
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
            action: 'billing.item_mapping_set',
            actorId: $this->actorId,
            targetType: 'item_billing_map',
            targetId: $this->mapId,
            metadata: [
                'inv_item_id' => $this->invItemId,
                'billing_service_id' => $this->billingServiceId,
            ],
        );
    }
}
