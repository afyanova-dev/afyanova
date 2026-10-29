<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * A lightweight reference to one of a patient's ACTIVE member coverages
 * (ADR-201 D-A). Returned by `InsuranceApi::patientCoverages` so a consumer
 * (Billing, Ins.4) can present the choices and pick one. NOT #[TypeScript].
 */
final readonly class InsuranceCoverageRef
{
    public function __construct(
        public string $memberCoverageId,
        public string $payerId,
        public string $planId,
        public string $priority,
        public ?string $memberNumber,
    ) {}
}
