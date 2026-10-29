<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Billing\Contracts\BillingApi;
use App\Domains\Insurance\Domain\AuthorizationReader;
use App\Domains\Insurance\Domain\ClaimLineRepository;
use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\Exception\AuthorizationNotFound;
use App\Domains\Insurance\Domain\Exception\ChargeAlreadyClaimed;
use App\Domains\Insurance\Domain\Exception\InsurancePatientNotFound;
use App\Domains\Insurance\Domain\Exception\InvoiceFacilityMismatch;
use App\Domains\Insurance\Domain\Exception\InvoiceNotFound;
use App\Domains\Insurance\Domain\Exception\InvoiceNotInsured;
use App\Domains\Insurance\Domain\Exception\InvoiceNotIssued;
use App\Domains\Insurance\Domain\Exception\NoClaimableLinesOnInvoice;
use App\Domains\Insurance\Domain\Exception\PayerContractNotActive;
use App\Domains\Insurance\Domain\Exception\PayerContractNotFound;
use App\Domains\Insurance\Domain\Model\Claim;
use App\Domains\Insurance\Domain\Model\ClaimLine;
use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\PayerContractReader;
use App\Domains\Insurance\Domain\ValueObject\ClaimStatus;
use App\Domains\Insurance\Domain\ValueObject\PayerContractStatus;
use App\Domains\Insurance\Infrastructure\Sequence\ClaimNumberSequence;
use App\Domains\Patient\Contracts\PatientApi;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates `CreateClaimFromInvoice` (ADR-204).
 * Enforces:
 *   - Invoice must be ISSUED and INSURANCE (ADR-201, ADR-204).
 *   - Verifies patient, coverage, optional contract/auth.
 *   - Mints `CLM-%08d` sequence counter (Sponsor Ruling D1).
 *   - Snapshots lines verbatim from Billing invoice (Sponsor Ruling D2).
 *   - Invariant 7: rejects duplicate live claims on any charge.
 */
final class CreateClaimFromInvoiceHandler
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ClaimLineRepository $lines,
        private readonly BillingApi $billingApi,
        private readonly PatientApi $patientApi,
        private readonly PayerContractReader $contractReader,
        private readonly AuthorizationReader $authReader,
        private readonly ClaimNumberSequence $sequence,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateClaimFromInvoice $command): void
    {
        $tenantId = $this->context->tenantId();

        // 1. Retrieve invoice via BillingApi
        $invoice = $this->billingApi->getInvoice($command->invoiceId);
        if ($invoice === null) {
            throw InvoiceNotFound::id($command->invoiceId);
        }

        // 2. Validate invoice status & payer type
        if ($invoice->status !== 'ISSUED') {
            throw InvoiceNotIssued::forInvoice($command->invoiceId, $invoice->status);
        }

        if ($invoice->payerType !== 'INSURANCE') {
            throw InvoiceNotInsured::forInvoice($command->invoiceId, $invoice->payerType);
        }

        if ((string) $invoice->facilityId !== $command->facilityId) {
            throw InvoiceFacilityMismatch::mismatch($command->invoiceId, (string) $invoice->facilityId, $command->facilityId);
        }

        if ($invoice->payerId === null || $invoice->memberCoverageId === null || $invoice->coveragePlanId === null) {
            throw InvoiceNotInsured::forInvoice($command->invoiceId, 'MISSING_COVERAGE_ATTRIBUTION');
        }

        // 3. Verify patient exists
        if (! $this->patientApi->patientExists($invoice->patientId)) {
            throw InsurancePatientNotFound::id($invoice->patientId);
        }

        // 4. Validate optional contract
        if ($command->contractId !== null) {
            $contract = $this->contractReader->summary($command->contractId);
            if ($contract->payerId !== $invoice->payerId) {
                throw PayerContractNotFound::id($command->contractId);
            }
            if ($contract->status !== PayerContractStatus::ACTIVE) {
                throw PayerContractNotActive::forContract($command->contractId, $contract->status);
            }
        }

        // 5. Validate optional authorization
        if ($command->authorizationId !== null) {
            try {
                $auth = $this->authReader->summary($command->authorizationId);
            } catch (AuthorizationNotFound) {
                throw AuthorizationNotFound::id($command->authorizationId);
            }
            if ($auth->memberCoverageId !== $invoice->memberCoverageId) {
                throw AuthorizationNotFound::id($command->authorizationId);
            }
        }

        // 6. Filter claimable lines (active, unreversed, payer responsibility > 0)
        $claimableInvoiceLines = [];
        $totalClaimed = '0.00';

        foreach ($invoice->lines as $line) {
            if (! $line->lineActive || $line->chargeReversed) {
                continue;
            }

            $payerResp = Money::of($line->payerResponsibility ?? '0.00');
            if (Money::isZero($payerResp) || Money::isNegative($payerResp)) {
                continue;
            }

            // Invariant 7 pre-check: charge must not already be claimed on an active line
            if ($this->lines->liveLineExistsForCharge($line->chargeId)) {
                throw ChargeAlreadyClaimed::forCharge($line->chargeId);
            }

            $claimableInvoiceLines[] = $line;
            $totalClaimed = Money::add($totalClaimed, $payerResp);
        }

        if (empty($claimableInvoiceLines)) {
            throw NoClaimableLinesOnInvoice::forInvoice($command->invoiceId);
        }

        // 7. Mint sequence number and persist inside atomic transaction
        DB::transaction(function () use ($command, $invoice, $tenantId, $claimableInvoiceLines, $totalClaimed): void {
            $claimNumber = $this->sequence->next($tenantId);
            $now = CarbonImmutable::now();

            $claim = new Claim([
                'id' => $command->claimId,
                'tenant_id' => $tenantId,
                'facility_id' => $command->facilityId,
                'claim_number' => $claimNumber,
                'invoice_id' => $command->invoiceId,
                'patient_id' => $invoice->patientId,
                'payer_id' => $invoice->payerId,
                'plan_id' => $invoice->coveragePlanId,
                'member_coverage_id' => $invoice->memberCoverageId,
                'contract_id' => $command->contractId,
                'authorization_id' => $command->authorizationId,
                'status' => ClaimStatus::DRAFT,
                'total_claimed_amount' => $totalClaimed,
                'currency' => 'TZS',
                'primary_diagnosis_code' => $command->primaryDiagnosisCode,
                'notes' => $command->notes,
                'created_by_user_id' => $command->actorId,
                'occurred_at' => $now,
                'recorded_at' => $now,
                'version' => 0,
            ]);

            $this->claims->save($claim);

            $lineNumber = 1;
            $serviceDate = $now->toDateString(); // Or invoice occurred_at date
            if ($invoice->occurredAt !== '') {
                $serviceDate = CarbonImmutable::parse($invoice->occurredAt)->toDateString();
            }

            foreach ($claimableInvoiceLines as $invLine) {
                $claimLine = new ClaimLine([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'claim_id' => $command->claimId,
                    'charge_id' => $invLine->chargeId,
                    'service_category' => $invLine->serviceCategory ?? 'CONSULTATION',
                    'service_code' => $invLine->billingServiceCode,
                    'service_description' => $invLine->description,
                    'service_date' => $serviceDate,
                    'quantity' => $invLine->quantity,
                    'unit_price' => $invLine->unitPrice,
                    'claimed_amount' => $invLine->payerResponsibility,
                    'authorization_id' => $command->authorizationId,
                    'diagnosis_code' => $command->primaryDiagnosisCode,
                    'line_number' => $lineNumber++,
                    'line_active' => true,
                    'occurred_at' => $now,
                    'recorded_at' => $now,
                    'version' => 0,
                ]);

                try {
                    $this->lines->save($claimLine);
                } catch (UniqueConstraintViolationException $e) {
                    throw ChargeAlreadyClaimed::forCharge($invLine->chargeId, $e);
                }
            }
        });
    }
}
