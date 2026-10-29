<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Application\ChargeAttempt;
use App\Domains\Billing\Domain\BillableEventRepository;
use App\Domains\Billing\Domain\BillingServiceRepository;
use App\Domains\Billing\Domain\Exception\BillingServiceInactive;
use App\Domains\Billing\Domain\ItemBillingMapRepository;
use App\Domains\Billing\Domain\Model\ItemBillingMap;
use App\Domains\Billing\Domain\ValueObject\BillablePendingReason;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `MapItemToBillingService` (ADR-189 D-A).
 *
 * The billing service must exist (`BillingServiceRepository::getById` — generic
 * `NotFound`) + be ACTIVE (`BillingServiceInactive`). Under a row lock on the
 * item's active mapping: deactivate the prior row, insert the new active row
 * (the partial unique `bill_item_billing_map_one_active_per_item` is the
 * backstop).
 *
 * Bill.3b (ADR-189 D-G, sponsor ruling C1) — after the mapping is set, sweep the
 * PENDING / `ITEM_UNMAPPED` billable events for the item and re-attempt each
 * (bounded — one item's backlog; the most common revenue leak self-heals). A
 * `SetTariff` sweep for `SERVICE_UNPRICED` is deliberately NOT done.
 */
final class MapItemToBillingServiceHandler
{
    public function __construct(
        private readonly ItemBillingMapRepository $maps,
        private readonly BillingServiceRepository $services,
        private readonly BillableEventRepository $billableEvents,
        private readonly ChargeAttempt $attempt,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(MapItemToBillingService $command): void
    {
        $service = $this->services->getById($command->billingServiceId);
        if (! $service->isActive()) {
            throw BillingServiceInactive::id($command->billingServiceId);
        }

        $current = $this->maps->activeForItemForUpdate($command->invItemId);
        if ($current !== null) {
            $current->deactivate();
            $this->maps->save($current);
        }

        $now = CarbonImmutable::now();

        $map = ItemBillingMap::map(
            id: $command->mapId,
            tenantId: $this->context->tenantId(),
            invItemId: $command->invItemId,
            billingServiceId: $command->billingServiceId,
            occurredAt: $now,
            recordedAt: $now,
        );

        $this->maps->save($map);

        foreach ($this->billableEvents->pendingForItem($command->invItemId, BillablePendingReason::ITEM_UNMAPPED) as $event) {
            $this->attempt->attempt($event, $command->actorId);
        }
    }
}
