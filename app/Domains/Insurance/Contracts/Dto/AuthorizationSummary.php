<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * Pre-authorization read-model projection (21-insurance-and-payer-domain.md
 * §56–§65; ADR-202 D-C). Returned by `GetAuthorization`,
 * `GetPatientAuthorizations`, `GetPendingAuthorizations`, and
 * `FindEffectiveAuthorization`. NOT #[TypeScript].
 */
final readonly class AuthorizationSummary
{
    public function __construct(
        public string $id,
        public string $authorizationNumber,
        public string $memberCoverageId,
        public string $patientId,
        public string $planId,
        public string $payerId,
        public string $serviceCategory,
        public ?string $serviceDescription,
        public ?string $diagnosisCode,
        public ?string $facilityId,
        public ?string $providerUserId,
        public ?string $requestedQuantity,
        public ?string $requestedAmount,
        public string $status,
        public ?string $externalAuthorizationNumber,
        public ?string $approvedQuantity,
        public ?string $approvedAmount,
        public ?string $validFrom,
        public ?string $validTo,
        public ?string $decisionReason,
        public string $requestedByUserId,
        public string $requestedAt,
        public ?string $decidedByUserId,
        public ?string $decidedAt,
        public ?string $expiredAt,
        public ?string $cancelledByUserId,
        public ?string $cancellationReason,
        public ?string $cancelledAt,
        public int $version,
    ) {}
}
