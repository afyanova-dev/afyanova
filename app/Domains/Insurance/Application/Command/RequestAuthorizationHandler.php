<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\AuthorizationRepository;
use App\Domains\Insurance\Domain\Exception\MemberCoverageNotActive;
use App\Domains\Insurance\Domain\Exception\MemberCoverageNotFound;
use App\Domains\Insurance\Domain\MemberCoverageRepository;
use App\Domains\Insurance\Domain\Model\Authorization;
use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Domains\Insurance\Infrastructure\Sequence\AuthorizationNumberSequence;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `RequestAuthorization` (ADR-202 D-B). `AuthorizationRepository`
 * is the FIRST repository param so the bus resolves `AuthorizationPolicy`
 * (class-level `request`).
 */
final class RequestAuthorizationHandler
{
    public function __construct(
        private readonly AuthorizationRepository $authorizations,
        private readonly MemberCoverageRepository $coverages,
        private readonly AuthorizationNumberSequence $numbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RequestAuthorization $command): void
    {
        try {
            $coverage = $this->coverages->getById($command->memberCoverageId);
        } catch (NotFound) {
            throw MemberCoverageNotFound::id($command->memberCoverageId);
        }

        if ($coverage->status !== MemberCoverage::STATUS_ACTIVE) {
            throw MemberCoverageNotActive::coverage($command->memberCoverageId, (string) $coverage->status);
        }

        $authNumber = $this->numbers->next($this->context->tenantId());
        $now = CarbonImmutable::now();

        $auth = Authorization::request(
            id: $command->authorizationId,
            tenantId: $this->context->tenantId(),
            authorizationNumber: $authNumber,
            memberCoverageId: $command->memberCoverageId,
            patientId: (string) $coverage->patient_id,
            planId: (string) $coverage->plan_id,
            payerId: (string) $coverage->payer_id,
            serviceCategory: $command->serviceCategory,
            serviceDescription: $command->serviceDescription,
            diagnosisCode: $command->diagnosisCode,
            facilityId: $command->facilityId,
            providerUserId: $command->providerUserId,
            requestedQuantity: $command->requestedQuantity,
            requestedAmount: $command->requestedAmount,
            requestedByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );

        $this->authorizations->save($auth);
    }
}
