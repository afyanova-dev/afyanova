<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\ItemBillingMapRepository;

/**
 * Orchestrates `DeactivateItemBillingMap` (ADR-189 D-A). No-op if the item has
 * no active mapping (idempotent).
 */
final class DeactivateItemBillingMapHandler
{
    public function __construct(private readonly ItemBillingMapRepository $maps) {}

    public function __invoke(DeactivateItemBillingMap $command): void
    {
        $current = $this->maps->activeForItemForUpdate($command->invItemId);

        if ($current === null) {
            return;
        }

        $current->deactivate();

        $this->maps->save($current);
    }
}
