<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One pharmacy order row with its fulfilment state.
 */
#[TypeScript]
final readonly class RxOrderItem
{
    public function __construct(
        public string $pharmacyOrderId,
        public string $orderNumber,
        public string $prescriptionId,
        public string $patientId,
        public string $patientDisplayName,
        public string $patientNumber,
        public ?int $patientAge,
        public string $patientSex,
        public ?string $medicationName,
        public string $status,
        public string $priority,
        public int $dispensingCount,
        public string $occurredAt,
        #[LiteralTypeScriptType('Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxDispensingItem>')]
        public array $dispensings = [],
        public ?string $externalIntakeId = null,
    ) {}
}
