<?php

declare(strict_types=1);

use App\Domains\Insurance\Application\Command\ActivatePayerContract;
use App\Domains\Insurance\Application\Command\ApproveAuthorization;
use App\Domains\Insurance\Application\Command\CancelAuthorization;
use App\Domains\Insurance\Application\Command\CancelClaim;
use App\Domains\Insurance\Application\Command\CreateClaimFromInvoice;
use App\Domains\Insurance\Application\Command\CreatePayerContract;
use App\Domains\Insurance\Application\Command\CreatePayerPlan;
use App\Domains\Insurance\Application\Command\CreateReplacementClaim;
use App\Domains\Insurance\Application\Command\DenyAuthorization;
use App\Domains\Insurance\Application\Command\EndPayerTariff;
use App\Domains\Insurance\Application\Command\ReconcileRemittanceLine;
use App\Domains\Insurance\Application\Command\RecordClaimAdjudication;
use App\Domains\Insurance\Application\Command\RecordRemittance;
use App\Domains\Insurance\Application\Command\RegisterMemberCoverage;
use App\Domains\Insurance\Application\Command\RegisterPayer;
use App\Domains\Insurance\Application\Command\RequestAuthorization;
use App\Domains\Insurance\Application\Command\SetPayerTariff;
use App\Domains\Insurance\Application\Command\SetPlanCoverageRule;
use App\Domains\Insurance\Application\Command\SubmitClaim;
use App\Domains\Insurance\Application\Command\TerminatePayerContract;
use App\Domains\Insurance\Application\Command\UpdateClaimDraft;
use App\Domains\Insurance\Application\Command\ValidateClaimReadiness;
use App\Domains\Insurance\Application\Query\EvaluateCoverage;
use App\Domains\Patient\Domain\Model\Patient;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 |----------------------------------------------------------------------
 | Insurance & Payer (PHASE 10 / Ins.1 — ADR-198) test helpers
 |----------------------------------------------------------------------
 */

require_once __DIR__.'/security.php'; // makeTenantWithUser

if (! function_exists('insuranceFixture')) {
    /**
     * A tenant + organization + facility + a user holding the Ins.1 permission
     * keys at the facility.
     *
     * @param  list<string>|null  $permissions
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function insuranceFixture(?array $permissions = null): array
    {
        $permissions ??= ['insurance.access', 'insurance.payers.manage', 'insurance.plans.manage'];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('dispatchInsurance')) {
    function dispatchInsurance(string $tenantId, ?string $facilityId, object $command): mixed
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
    }
}

if (! function_exists('queryInsurance')) {
    function queryInsurance(string $tenantId, ?string $facilityId, object $query): mixed
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch($query));
    }
}

if (! function_exists('insuranceUserWithKeys')) {
    /**
     * A fresh user in the tenant with an ACTIVE facility-scoped role carrying
     * exactly the given permission keys (the `billingUserWithKeys` shape).
     *
     * @param  list<string>  $keys
     */
    function insuranceUserWithKeys(string $tenantId, string $facilityId, array $keys): User
    {
        $user = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);

        TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $user, $keys): void {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'ins_role_'.Str::lower(Str::random(6)),
                'name' => 'Insurance', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($keys as $key) {
                $permId = DB::table('idn_permissions')->where('key', $key)->value('id')
                    ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                        'id' => $id, 'key' => $key, 'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                        'created_at' => now(), 'updated_at' => now(),
                    ]));

                DB::table('idn_permission_role')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                    'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('idn_staff_identities')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
                'is_staff' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
                'role_id' => $roleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
                'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $user;
    }
}

if (! function_exists('insuranceRegisterPayer')) {
    /**
     * Dispatch `RegisterPayer` and return the new payer id.
     */
    function insuranceRegisterPayer(
        string $tenantId,
        string $facilityId,
        string $actorId,
        ?string $code = null,
        string $payerType = 'INSURANCE',
    ): string {
        $id = (string) Str::uuid7();
        $code ??= 'PAY-'.Str::upper(Str::random(5));
        dispatchInsurance($tenantId, $facilityId, new RegisterPayer(
            payerId: $id, actorId: $actorId, code: $code, name: $code.' payer', payerType: $payerType,
        ));

        return $id;
    }
}

if (! function_exists('insurancePatient')) {
    /** A fresh patient in the tenant — the `RegisterMemberCoverage` target. */
    function insurancePatient(string $tenantId): string
    {
        return TenantContextScope::run($tenantId, null, fn (): string => (string) Patient::factory()
            ->forTenant($tenantId)
            ->create()->id);
    }
}

if (! function_exists('insuranceRegisterCoverage')) {
    /**
     * Dispatch `RegisterMemberCoverage` and return the new coverage id.
     */
    function insuranceRegisterCoverage(
        string $tenantId,
        string $facilityId,
        string $actorId,
        string $patientId,
        string $planId,
        string $relationship = 'PRIMARY_MEMBER',
        string $priority = 'PRIMARY',
        string $effectiveFrom = '2020-01-01',
        ?string $effectiveTo = null,
        ?string $memberNumber = null,
        ?string $principalMemberCoverageId = null,
    ): string {
        $id = (string) Str::uuid7();
        dispatchInsurance($tenantId, $facilityId, new RegisterMemberCoverage(
            memberCoverageId: $id, actorId: $actorId, patientId: $patientId, planId: $planId,
            relationship: $relationship, priority: $priority, effectiveFrom: $effectiveFrom,
            effectiveTo: $effectiveTo, memberNumber: $memberNumber,
            principalMemberCoverageId: $principalMemberCoverageId,
        ));

        return $id;
    }
}

if (! function_exists('insuranceCreatePlan')) {
    /**
     * Dispatch `CreatePayerPlan` and return the new plan id.
     */
    function insuranceCreatePlan(
        string $tenantId,
        string $facilityId,
        string $actorId,
        string $payerId,
        ?string $code = null,
        ?string $effectiveFrom = null,
        ?string $effectiveTo = null,
    ): string {
        $id = (string) Str::uuid7();
        $code ??= 'PLN-'.Str::upper(Str::random(5));
        dispatchInsurance($tenantId, $facilityId, new CreatePayerPlan(
            planId: $id, actorId: $actorId, payerId: $payerId, code: $code, name: $code.' plan',
            effectiveFrom: $effectiveFrom, effectiveTo: $effectiveTo,
        ));

        return $id;
    }
}

if (! function_exists('insuranceSetCoverageRule')) {
    /**
     * Dispatch `SetPlanCoverageRule` and return the new rule id.
     */
    function insuranceSetCoverageRule(
        string $tenantId,
        string $facilityId,
        string $actorId,
        string $planId,
        string $serviceCategory,
        string $coverageStatus = 'COVERED',
        string $effectiveFrom = '2020-01-01',
        ?int $coveragePercent = null,
        ?string $coPayAmount = null,
        ?string $fixedBenefitAmount = null,
        ?bool $authorizationRequired = null,
    ): string {
        $id = (string) Str::uuid7();
        dispatchInsurance($tenantId, $facilityId, new SetPlanCoverageRule(
            ruleId: $id, actorId: $actorId, planId: $planId, serviceCategory: $serviceCategory,
            coverageStatus: $coverageStatus, effectiveFrom: $effectiveFrom, coveragePercent: $coveragePercent,
            coPayAmount: $coPayAmount, fixedBenefitAmount: $fixedBenefitAmount,
            authorizationRequired: $authorizationRequired,
        ));

        return $id;
    }
}

if (! function_exists('insuranceEvaluateCoverage')) {
    /**
     * Dispatch `EvaluateCoverage` and return the `CoverageDecision`.
     */
    function insuranceEvaluateCoverage(
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $planId,
        string $serviceCategory,
        ?string $chargeAmount = null,
        ?string $onDate = null,
    ): mixed {
        return queryInsurance($tenantId, $facilityId, new EvaluateCoverage(
            patientId: $patientId, planId: $planId, serviceCategory: $serviceCategory,
            chargeAmount: $chargeAmount, onDate: $onDate,
        ));
    }
}

if (! function_exists('insuranceRequestAuthorization')) {
    /**
     * Dispatch `RequestAuthorization` and return the new authorization id.
     */
    function insuranceRequestAuthorization(
        string $tenantId,
        string $facilityId,
        string $actorId,
        string $memberCoverageId,
        string $serviceCategory,
        ?string $serviceDescription = null,
        ?string $diagnosisCode = null,
        ?string $requestedQuantity = null,
        ?string $requestedAmount = null,
    ): string {
        $id = (string) Str::uuid7();
        dispatchInsurance($tenantId, $facilityId, new RequestAuthorization(
            authorizationId: $id,
            actorId: $actorId,
            memberCoverageId: $memberCoverageId,
            serviceCategory: $serviceCategory,
            serviceDescription: $serviceDescription,
            diagnosisCode: $diagnosisCode,
            facilityId: $facilityId,
            requestedQuantity: $requestedQuantity,
            requestedAmount: $requestedAmount,
        ));

        return $id;
    }
}

if (! function_exists('insuranceApproveAuthorization')) {
    /**
     * Dispatch `ApproveAuthorization`.
     */
    function insuranceApproveAuthorization(
        string $tenantId,
        string $facilityId,
        string $actorId,
        string $authorizationId,
        string $externalAuthorizationNumber = 'AUTH-EXT-001',
        string $validFrom = '2026-01-01',
        string $validTo = '2026-12-31',
        ?string $approvedQuantity = null,
        ?string $approvedAmount = null,
        ?string $note = null,
    ): void {
        dispatchInsurance($tenantId, $facilityId, new ApproveAuthorization(
            authorizationId: $authorizationId,
            actorId: $actorId,
            externalAuthorizationNumber: $externalAuthorizationNumber,
            validFrom: $validFrom,
            validTo: $validTo,
            approvedQuantity: $approvedQuantity,
            approvedAmount: $approvedAmount,
            note: $note,
        ));
    }
}

if (! function_exists('insuranceDenyAuthorization')) {
    /**
     * Dispatch `DenyAuthorization`.
     */
    function insuranceDenyAuthorization(
        string $tenantId,
        string $facilityId,
        string $actorId,
        string $authorizationId,
        string $reasonCode = 'SERVICE_NOT_COVERED',
        ?string $note = null,
    ): void {
        dispatchInsurance($tenantId, $facilityId, new DenyAuthorization(
            authorizationId: $authorizationId,
            actorId: $actorId,
            reasonCode: $reasonCode,
            note: $note,
        ));
    }
}

if (! function_exists('insuranceCancelAuthorization')) {
    /**
     * Dispatch `CancelAuthorization`.
     */
    function insuranceCancelAuthorization(
        string $tenantId,
        string $facilityId,
        string $actorId,
        string $authorizationId,
        string $reasonCode = 'PAYER_REVOKED',
    ): void {
        dispatchInsurance($tenantId, $facilityId, new CancelAuthorization(
            authorizationId: $authorizationId,
            actorId: $actorId,
            reasonCode: $reasonCode,
        ));
    }
}

if (! function_exists('insuranceCreateContract')) {
    /**
     * Dispatch `CreatePayerContract` and return the new contract id.
     */
    function insuranceCreateContract(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $payerId,
        string $name = 'Standard Provider Agreement',
        CarbonImmutable|string $effectiveFrom = '2026-01-01',
        CarbonImmutable|string|null $effectiveTo = null,
        ?string $reference = null,
        ?string $contractFacilityId = null,
        ?string $terms = null,
    ): string {
        $id = (string) Str::uuid7();
        $from = $effectiveFrom instanceof CarbonImmutable ? $effectiveFrom : CarbonImmutable::parse($effectiveFrom)->startOfDay();
        $to = $effectiveTo === null ? null : ($effectiveTo instanceof CarbonImmutable ? $effectiveTo : CarbonImmutable::parse($effectiveTo)->startOfDay());

        dispatchInsurance($tenantId, $facilityId, new CreatePayerContract(
            contractId: $id,
            actorId: $actorId,
            payerId: $payerId,
            name: $name,
            effectiveFrom: $from,
            effectiveTo: $to,
            reference: $reference,
            facilityId: $contractFacilityId,
            terms: $terms,
        ));

        return $id;
    }
}

if (! function_exists('insuranceActivateContract')) {
    /**
     * Dispatch `ActivatePayerContract`.
     */
    function insuranceActivateContract(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $contractId,
    ): void {
        dispatchInsurance($tenantId, $facilityId, new ActivatePayerContract(
            contractId: $contractId,
            actorId: $actorId,
        ));
    }
}

if (! function_exists('insuranceTerminateContract')) {
    /**
     * Dispatch `TerminatePayerContract`.
     */
    function insuranceTerminateContract(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $contractId,
        string $reason = 'Mutual agreement termination',
    ): void {
        dispatchInsurance($tenantId, $facilityId, new TerminatePayerContract(
            contractId: $contractId,
            actorId: $actorId,
            reason: $reason,
        ));
    }
}

if (! function_exists('insuranceSetTariff')) {
    /**
     * Dispatch `SetPayerTariff` and return the new tariff id.
     */
    function insuranceSetTariff(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $contractId,
        string $serviceCategory,
        string $amount,
        string $effectiveFrom = '2026-01-01',
        string $currency = 'TZS',
    ): string {
        $id = (string) Str::uuid7();
        dispatchInsurance($tenantId, $facilityId, new SetPayerTariff(
            tariffId: $id,
            actorId: $actorId,
            contractId: $contractId,
            serviceCategory: $serviceCategory,
            amount: $amount,
            effectiveFrom: $effectiveFrom,
            currency: $currency,
        ));

        return $id;
    }
}

if (! function_exists('insuranceEndTariff')) {
    /**
     * Dispatch `EndPayerTariff`.
     */
    function insuranceEndTariff(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $tariffId,
        string $effectiveTo,
    ): void {
        dispatchInsurance($tenantId, $facilityId, new EndPayerTariff(
            tariffId: $tariffId,
            actorId: $actorId,
            effectiveTo: $effectiveTo,
        ));
    }
}

if (! function_exists('insuranceCreateClaimFromInvoice')) {
    /**
     * Dispatch `CreateClaimFromInvoice` and return the new claim id.
     */
    function insuranceCreateClaimFromInvoice(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $invoiceId,
        ?string $primaryDiagnosisCode = null,
        ?string $authorizationId = null,
        ?string $contractId = null,
        ?string $notes = null,
    ): string {
        $id = (string) Str::uuid7();
        $claimFacilityId = $facilityId ?? (string) DB::table('bill_invoices')->where('id', $invoiceId)->value('facility_id');
        dispatchInsurance($tenantId, $facilityId, new CreateClaimFromInvoice(
            claimId: $id,
            actorId: $actorId,
            invoiceId: $invoiceId,
            facilityId: $claimFacilityId,
            contractId: $contractId,
            authorizationId: $authorizationId,
            primaryDiagnosisCode: $primaryDiagnosisCode,
            notes: $notes,
        ));

        return $id;
    }
}

if (! function_exists('insuranceUpdateClaimDraft')) {
    /**
     * Dispatch `UpdateClaimDraft`.
     */
    function insuranceUpdateClaimDraft(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $claimId,
        ?string $primaryDiagnosisCode = null,
        ?string $authorizationId = null,
        ?string $notes = null,
    ): void {
        dispatchInsurance($tenantId, $facilityId, new UpdateClaimDraft(
            claimId: $claimId,
            actorId: $actorId,
            primaryDiagnosisCode: $primaryDiagnosisCode,
            authorizationId: $authorizationId,
            notes: $notes,
        ));
    }
}

if (! function_exists('insuranceValidateClaimReadiness')) {
    /**
     * Dispatch `ValidateClaimReadiness`.
     */
    function insuranceValidateClaimReadiness(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $claimId,
    ): void {
        dispatchInsurance($tenantId, $facilityId, new ValidateClaimReadiness(
            claimId: $claimId,
            actorId: $actorId,
        ));
    }
}

if (! function_exists('insuranceSubmitClaim')) {
    /**
     * Dispatch `SubmitClaim`.
     */
    function insuranceSubmitClaim(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $claimId,
    ): void {
        dispatchInsurance($tenantId, $facilityId, new SubmitClaim(
            claimId: $claimId,
            actorId: $actorId,
        ));
    }
}

if (! function_exists('insuranceCancelClaim')) {
    /**
     * Dispatch `CancelClaim`.
     */
    function insuranceCancelClaim(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $claimId,
        string $reason = 'Cancelled by user',
    ): void {
        dispatchInsurance($tenantId, $facilityId, new CancelClaim(
            claimId: $claimId,
            actorId: $actorId,
            reason: $reason,
        ));
    }
}

if (! function_exists('insuranceCreateReplacementClaim')) {
    /**
     * Dispatch `CreateReplacementClaim` and return the new replacement claim id.
     */
    function insuranceCreateReplacementClaim(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $supersededClaimId,
        ?string $primaryDiagnosisCode = null,
        ?string $authorizationId = null,
        ?string $notes = null,
    ): string {
        $id = (string) Str::uuid7();
        dispatchInsurance($tenantId, $facilityId, new CreateReplacementClaim(
            replacementClaimId: $id,
            actorId: $actorId,
            supersedesClaimId: $supersededClaimId,
            primaryDiagnosisCode: $primaryDiagnosisCode,
            authorizationId: $authorizationId,
            notes: $notes,
        ));

        return $id;
    }
}

if (! function_exists('insuranceRecordAdjudication')) {
    /**
     * Dispatch `RecordClaimAdjudication` and return the new adjudication id.
     *
     * @param  list<array{
     *     claimLineId: string,
     *     status: string,
     *     claimedAmount: string,
     *     approvedAmount: string,
     *     deniedAmount: string,
     *     patientResponsibility: string,
     *     contractualAdjustment: string,
     *     denialReasonCode?: ?string,
     *     remarks?: ?string,
     * }>  $lines
     */
    function insuranceRecordAdjudication(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $claimId,
        string $status,
        string $adjudicationDate,
        array $lines,
        ?string $externalReference = null,
        ?string $notes = null,
    ): string {
        $id = (string) Str::uuid7();
        dispatchInsurance($tenantId, $facilityId, new RecordClaimAdjudication(
            adjudicationId: $id,
            actorId: $actorId,
            claimId: $claimId,
            status: $status,
            adjudicationDate: $adjudicationDate,
            lines: $lines,
            externalReference: $externalReference,
            notes: $notes,
        ));

        return $id;
    }
}

if (! function_exists('insuranceRecordRemittance')) {
    /**
     * Dispatch `RecordRemittance` and return the new remittance id.
     *
     * @param  list<array{
     *     claimId?: ?string,
     *     adjudicationId?: ?string,
     *     externalClaimReference?: ?string,
     *     claimedAmount: string,
     *     paidAmount: string,
     *     contractualAdjustment: string,
     *     patientResponsibility: string,
     *     deniedAmount: string,
     *     status?: string,
     *     discrepancyReason?: ?string,
     * }>  $lines
     */
    function insuranceRecordRemittance(
        string $tenantId,
        string $facilityId,
        string $actorId,
        string $payerId,
        string $paymentReference,
        string $totalAmount,
        string $remittanceDate,
        string $paymentMethod = 'BANK_TRANSFER',
        array $lines = [],
        ?string $notes = null,
    ): string {
        $id = (string) Str::uuid7();
        dispatchInsurance($tenantId, $facilityId, new RecordRemittance(
            remittanceId: $id,
            actorId: $actorId,
            facilityId: $facilityId,
            payerId: $payerId,
            paymentReference: $paymentReference,
            paymentMethod: $paymentMethod,
            remittanceDate: $remittanceDate,
            totalAmount: $totalAmount,
            lines: $lines,
            notes: $notes,
        ));

        return $id;
    }
}

if (! function_exists('insuranceReconcileRemittanceLine')) {
    /**
     * Dispatch `ReconcileRemittanceLine`.
     */
    function insuranceReconcileRemittanceLine(
        string $tenantId,
        ?string $facilityId,
        string $actorId,
        string $remittanceId,
        string $remittanceLineId,
        string $claimId,
        string $paidAmount,
        string $contractualAdjustment = '0.00',
        string $patientResponsibility = '0.00',
        string $deniedAmount = '0.00',
        ?string $discrepancyReason = null,
    ): void {
        dispatchInsurance($tenantId, $facilityId, new ReconcileRemittanceLine(
            remittanceId: $remittanceId,
            actorId: $actorId,
            remittanceLineId: $remittanceLineId,
            claimId: $claimId,
            paidAmount: $paidAmount,
            contractualAdjustment: $contractualAdjustment,
            patientResponsibility: $patientResponsibility,
            deniedAmount: $deniedAmount,
            discrepancyReason: $discrepancyReason,
        ));
    }
}
