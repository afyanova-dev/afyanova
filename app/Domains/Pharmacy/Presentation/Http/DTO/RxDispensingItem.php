<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One dispensing row — item identity resolved for display (code + name + unit
 * come from Inventory; Pharmacy never duplicates them).
 */
#[TypeScript]
final readonly class RxDispensingItem
{
    public function __construct(
        public string $dispensingId,
        public string $dispensingNumber,
        public string $itemCode,
        public string $itemName,
        public string $quantity,
        public string $quantityUnit,
        public string $status,
        public ?string $pickupStatus,
        public ?string $reversalId,
        public string $receivedAt,
    ) {}
}
