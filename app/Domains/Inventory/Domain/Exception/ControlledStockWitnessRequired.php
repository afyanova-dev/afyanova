<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A DECREASE adjustment targets an item flagged as a controlled medicine but
 * carries no witness name (frontend-foundation-inventory-operations.md Phase 0
 * disposition policy: "destruction witness required for controlled drugs";
 * ADR-170 D-H applies the same two-person floor to the adjustment itself).
 *
 * Every reduction of controlled stock is a controlled act — disposal, loss,
 * damage, count variance or a correction — so the rule is not limited to the
 * `EXPIRY` reason. Raising stock, and any movement of an uncontrolled item, is
 * unaffected.
 */
final class ControlledStockWitnessRequired extends DomainException
{
    public static function forItem(string $itemCode): self
    {
        return new self(sprintf(
            'Item [%s] is a controlled medicine: removing stock needs the name of a witness (Phase 0 disposition policy).',
            $itemCode,
        ));
    }

    public function errorCode(): string
    {
        return 'controlled_stock_witness_required';
    }
}
