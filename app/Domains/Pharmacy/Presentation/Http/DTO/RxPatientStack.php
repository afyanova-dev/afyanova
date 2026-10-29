<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One patient's full medication story for the patient-meds canvas — every
 * script with status, every order with fulfilment. Read-model only.
 */
#[TypeScript]
final readonly class RxPatientStack
{
    /**
     * @param  list<RxPrescriptionItem>  $prescriptions
     * @param  list<RxOrderItem>  $orders
     */
    public function __construct(
        public string $patientId,
        public string $displayName,
        public string $patientNumber,
        #[LiteralTypeScriptType('Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxPrescriptionItem>')]
        public array $prescriptions = [],
        #[LiteralTypeScriptType('Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxOrderItem>')]
        public array $orders = [],
    ) {}
}
