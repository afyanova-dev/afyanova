<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One prescription row — electronic or transcribed paper (source shown, never
 * hidden: the two origination paths must stay distinguishable forever).
 */
#[TypeScript]
final readonly class RxPrescriptionItem
{
    public function __construct(
        public string $prescriptionId,
        public string $prescriptionNumber,
        public string $patientId,
        public string $patientDisplayName,
        public string $patientNumber,
        public ?int $patientAge,
        public string $patientSex,
        public string $source,
        public string $status,
        public ?string $medicationName,
        public ?string $medicationCode,
        public ?string $inventoryItemId,
        public ?string $prescriberName,
        public string $occurredAt,
        public string $recordedAt,
        public ?string $route,
        public ?string $urgency,
        public ?string $dose,
        public ?string $frequency,
        public ?string $duration,
        public ?string $prescribedQuantity,
        public ?string $dosingInstruction,
        public ?string $transcriptionNote,
        public ?string $externalIntakeId = null,
        public ?string $lastDispenserName = null,
    ) {}
}
