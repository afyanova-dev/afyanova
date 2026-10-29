<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One captured-but-unbillable event on the revenue-leakage worklist
 * (Bill.3b §130/§131). Display identity only — the money decision (map +
 * retry) happens through commands.
 */
#[TypeScript]
final readonly class CatalogPendingEventItem
{
    public function __construct(
        public string $billableEventId,
        public string $patientDisplayName,
        public string $patientNumber,
        public string $quantity,
        public string $pendingReason,
        public string $occurredAt,
    ) {}
}
