<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CreateClinicalPlan;
use App\Domains\Clinical\Application\Command\CreateClinicalPlanResult;
use App\Domains\Clinical\Application\Command\RecordPrescribingDecision;
use App\Domains\Clinical\Application\Command\RecordPrescribingDecisionResult;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

/*
 |----------------------------------------------------------------------
 | Clinical Plan & Prescribing Decision (PHASE 6 / C.7 — ADR-143) test helper
 |----------------------------------------------------------------------
 */

require_once __DIR__.'/clinical.php';
require_once __DIR__.'/problem.php'; // for grantPermissionsToRole

if (! function_exists('planPrescribingFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function planPrescribingFixture(?array $permissions = null): array
    {
        $permissions ??= [
            'clinical.plans.read',
            'clinical.plans.record',
            'clinical.prescribing.read',
            'clinical.prescribing.record',
        ];

        $fixture = clinicalFixture();
        grantPermissionsToRole((string) $fixture['tenant']->id, $fixture['role_id'], $permissions);

        return $fixture;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    function clinicalCreatePlan(
        string $tenantId,
        ?string $facilityId,
        string $patientId,
        string $encounterId,
        string $actorId,
        array $overrides = [],
    ): string {
        $planId = $overrides['clinicalPlanId'] ?? (string) Str::uuid7();

        /** @var CreateClinicalPlanResult $result */
        $result = TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new CreateClinicalPlan(
                clinicalPlanId: $planId,
                actorId: $actorId,
                patientId: $patientId,
                encounterId: $encounterId,
                planType: $overrides['planType'] ?? ClinicalPlanType::GENERAL,
                clinicalIntent: $overrides['clinicalIntent'] ?? 'Standard treatment plan',
                goals: $overrides['goals'] ?? null,
                plannedActions: $overrides['plannedActions'] ?? null,
                instructions: $overrides['instructions'] ?? null,
                followUp: $overrides['followUp'] ?? null,
                indicationDiagnosisId: $overrides['indicationDiagnosisId'] ?? null,
                indicationProblemId: $overrides['indicationProblemId'] ?? null,
                occurredAt: $overrides['occurredAt'] ?? null,
                reason: $overrides['reason'] ?? null,
            ),
        ));

        return $result->clinicalPlanId;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    function clinicalRecordPrescribingDecision(
        string $tenantId,
        ?string $facilityId,
        string $patientId,
        string $encounterId,
        string $actorId,
        array $overrides = [],
    ): string {
        $decisionId = $overrides['prescribingDecisionId'] ?? (string) Str::uuid7();

        // ADR-183 D-E / D-N: a structured authorised quantity is supplied
        // INSTEAD OF the free-text `quantity` — passing `quantityValue` nulls it.
        $structuredQuantity = array_key_exists('quantityValue', $overrides);

        /** @var RecordPrescribingDecisionResult $result */
        $result = TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new RecordPrescribingDecision(
                prescribingDecisionId: $decisionId,
                actorId: $actorId,
                patientId: $patientId,
                encounterId: $encounterId,
                medication: $overrides['medication'] ?? MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm'),
                dose: $overrides['dose'] ?? '500 mg',
                route: $overrides['route'] ?? 'ORAL',
                frequency: $overrides['frequency'] ?? 'TDS',
                duration: $overrides['duration'] ?? '7 days',
                quantity: $structuredQuantity
                    ? null
                    : (array_key_exists('quantity', $overrides) ? $overrides['quantity'] : '21 capsules'),
                quantityValue: $overrides['quantityValue'] ?? null,
                quantityUnit: $overrides['quantityUnit'] ?? null,
                dosingInstruction: $overrides['dosingInstruction'] ?? 'Take with food',
                therapeuticIntent: $overrides['therapeuticIntent'] ?? 'Eradication of bacterial infection',
                indication: $overrides['indication'] ?? 'Acute bacterial sinusitis',
                urgency: $overrides['urgency'] ?? 'ROUTINE',
                clinicalPlanId: $overrides['clinicalPlanId'] ?? null,
                indicationDiagnosisId: $overrides['indicationDiagnosisId'] ?? null,
                indicationProblemId: $overrides['indicationProblemId'] ?? null,
                occurredAt: $overrides['occurredAt'] ?? null,
                reason: $overrides['reason'] ?? null,
            ),
        ));

        return $result->prescribingDecisionId;
    }
}
