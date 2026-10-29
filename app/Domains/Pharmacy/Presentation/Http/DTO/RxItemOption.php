<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One inventory item option for the dispense picker (code + name + base unit).
 * Stock figures are NOT snapshotted here — availability is read live per
 * selected item so the pharmacist never dispenses from a stale number.
 *
 * `trackBatch` tells the panel whether batch/expiry selection must be explicit
 * for this item (`ItemSummary::trackBatch`).
 */
#[TypeScript]
final readonly class RxItemOption
{
    public function __construct(
        public string $itemId,
        public string $code,
        public string $name,
        public string $uom,
        public bool $active,
        public string $itemType,
        public bool $trackBatch,
    ) {}
}
