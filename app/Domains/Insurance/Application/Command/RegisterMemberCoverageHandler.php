<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Contracts\Dto\PayerPlanSummary;
use App\Domains\Insurance\Domain\Exception\CoverageOutsidePlanWindow;
use App\Domains\Insurance\Domain\Exception\DuplicateActiveCoverage;
use App\Domains\Insurance\Domain\Exception\InsurancePatientNotFound;
use App\Domains\Insurance\Domain\Exception\PayerOrPlanInactive;
use App\Domains\Insurance\Domain\Exception\PrincipalCoverageInvalid;
use App\Domains\Insurance\Domain\MemberCoverageRepository;
use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Domains\Insurance\Domain\Model\Payer;
use App\Domains\Insurance\Domain\Model\Plan;
use App\Domains\Insurance\Domain\PayerReader;
use App\Domains\Insurance\Domain\PlanReader;
use App\Domains\Insurance\Domain\ValueObject\BeneficiaryRelationship;
use App\Domains\Patient\Contracts\PatientApi;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `RegisterMemberCoverage` (ADR-199 D-E). The first cross-domain
 * read in Insurance (`PatientApi::patientExists` — a read, no PHI, not a
 * write). `MemberCoverageRepository` is the FIRST repository param so the bus
 * resolves `MemberCoveragePolicy` (class-level `manage`).
 */
final class RegisterMemberCoverageHandler
{
    public function __construct(
        private readonly MemberCoverageRepository $coverages,
        private readonly PayerReader $payers,
        private readonly PlanReader $plans,
        private readonly PatientApi $patients,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RegisterMemberCoverage $command): void
    {
        if (! $this->patients->patientExists($command->patientId)) {
            throw InsurancePatientNotFound::id($command->patientId);
        }

        $plan = $this->plans->summary($command->planId);        // PayerPlanNotFound if absent
        $payer = $this->payers->summary($plan->payerId);        // PayerNotFound if absent

        if ($plan->status !== Plan::STATUS_ACTIVE) {
            throw PayerOrPlanInactive::plan($command->planId);
        }
        if ($payer->status !== Payer::STATUS_ACTIVE) {
            throw PayerOrPlanInactive::payer($plan->payerId);
        }

        $this->assertWithinPlanWindow($command, $plan);
        $this->assertPrincipalConsistent($command);

        $now = CarbonImmutable::now();

        $coverage = MemberCoverage::register(
            id: $command->memberCoverageId,
            tenantId: $this->context->tenantId(),
            patientId: $command->patientId,
            planId: $command->planId,
            payerId: $plan->payerId,
            memberNumber: $command->memberNumber,
            policyNumber: $command->policyNumber,
            cardNumber: $command->cardNumber,
            relationship: $command->relationship,
            principalMemberCoverageId: $command->principalMemberCoverageId,
            priority: $command->priority,
            effectiveFrom: $command->effectiveFrom,
            effectiveTo: $command->effectiveTo,
            createdByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->coverages->save($coverage);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'ins_member_coverages_one_active_per_priority')) {
                throw DuplicateActiveCoverage::forPatientAtPriority($command->patientId, $command->priority);
            }
            throw $e;
        }
    }

    private function assertWithinPlanWindow(RegisterMemberCoverage $command, PayerPlanSummary $plan): void
    {
        if ($plan->effectiveFrom !== null
            && $command->effectiveFrom->lt(CarbonImmutable::parse($plan->effectiveFrom))) {
            throw CoverageOutsidePlanWindow::id($command->planId);
        }

        if ($plan->effectiveTo !== null) {
            $planEnd = CarbonImmutable::parse($plan->effectiveTo);
            $coverageEnd = $command->effectiveTo;
            if ($command->effectiveFrom->gt($planEnd) || ($coverageEnd !== null && $coverageEnd->gt($planEnd))) {
                throw CoverageOutsidePlanWindow::id($command->planId);
            }
        }
    }

    private function assertPrincipalConsistent(RegisterMemberCoverage $command): void
    {
        if ($command->relationship === BeneficiaryRelationship::PRIMARY_MEMBER) {
            if ($command->principalMemberCoverageId !== null) {
                throw PrincipalCoverageInvalid::primaryMemberHasPrincipal();
            }

            return;
        }

        if ($command->principalMemberCoverageId !== null
            && ! $this->coverages->exists($command->principalMemberCoverageId)) {
            throw PrincipalCoverageInvalid::unknown($command->principalMemberCoverageId);
        }
    }
}
