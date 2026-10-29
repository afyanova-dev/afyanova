<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\BillingCatalogReader;
use App\Domains\Billing\Domain\Exception\CoverageNotForInvoicePatient;
use App\Domains\Billing\Domain\Exception\IllegalInvoiceTransition;
use App\Domains\Billing\Domain\InvoiceLineRepository;
use App\Domains\Billing\Domain\InvoiceRepository;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\InvoiceCoverageStatus;
use App\Domains\Insurance\Contracts\Dto\InsuranceCoverageRef;
use App\Domains\Insurance\Contracts\InsuranceApi;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `SetInvoiceCoverage` (ADR-201 D-C). DRAFT only. `null` reverts
 * to SELF_PAY. Otherwise: the `memberCoverageId` must be one of the patient's
 * ACTIVE coverages (`InsuranceApi::patientCoverages`), then each live line is
 * evaluated (`InsuranceApi::evaluateCoverage`) and the split summed to the
 * invoice.
 *
 * `InvoiceRepository` is the FIRST repository parameter so the bus resolves
 * `InvoicePolicy` (per-subject `manage`). `InsuranceApi`'s reads dispatch
 * protected queries — the acting user also needs `insurance.access`.
 */
final class SetInvoiceCoverageHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceLineRepository $lines,
        private readonly BillingCatalogReader $catalog,
        private readonly InsuranceApi $insurance,
    ) {}

    public function __invoke(SetInvoiceCoverage $command): void
    {
        $invoice = $this->invoices->getByIdForUpdate($command->invoiceId);

        if (! $invoice->isDraft()) {
            throw IllegalInvoiceTransition::fromStatus('SetInvoiceCoverage', (string) $invoice->status);
        }

        $liveLines = $this->lines->liveLinesForInvoiceForUpdate($command->invoiceId);

        if ($command->memberCoverageId === null) {
            $invoice->clearCoverage();
            foreach ($liveLines as $line) {
                $line->clearCoverage();
                $this->lines->save($line);
            }
            $this->invoices->save($invoice);

            return;
        }

        $ref = $this->coverageRef($command->memberCoverageId, (string) $invoice->patient_id, $command->invoiceId);

        $payerSum = '0.00';
        $patientSum = '0.00';
        $statuses = [];
        $confidences = [];
        $source = null;

        foreach ($liveLines as $line) {
            $category = (string) $line->service_category
                ?: ($this->catalog->categoryForCode((string) $line->billing_service_code) ?? 'OTHER');

            $decision = $this->insurance->evaluateCoverage(
                (string) $invoice->patient_id,
                $ref->planId,
                $category,
                (string) $line->amount,
                $command->onDate,
            );

            $linePayer = Money::of($decision->payerResponsibilityEstimate ?? '0.00');
            $linePatient = Money::of($decision->patientResponsibilityEstimate ?? (string) $line->amount);

            $line->applyCoverage($linePayer, $linePatient, $decision->coverageStatus);
            $this->lines->save($line);

            $payerSum = Money::add($payerSum, $linePayer);
            $patientSum = Money::add($patientSum, $linePatient);
            $statuses[] = $decision->coverageStatus;
            $confidences[] = $decision->confidence;
            $source ??= $decision->source;
        }

        $invoice->applyCoverage(
            payerId: $ref->payerId,
            memberCoverageId: $command->memberCoverageId,
            coveragePlanId: $ref->planId,
            payerResponsibility: $payerSum,
            patientResponsibility: $patientSum,
            coverageStatus: InvoiceCoverageStatus::rollup($statuses),
            coverageSource: $source ?? 'LOCAL_POLICY',
            coverageConfidence: $this->weakestConfidence($confidences),
            evaluatedAt: CarbonImmutable::now(),
        );

        $this->invoices->save($invoice);
    }

    private function coverageRef(string $memberCoverageId, string $patientId, string $invoiceId): InsuranceCoverageRef
    {
        foreach ($this->insurance->patientCoverages($patientId) as $ref) {
            if ($ref->memberCoverageId === $memberCoverageId) {
                return $ref;
            }
        }

        throw CoverageNotForInvoicePatient::mismatch($memberCoverageId, $invoiceId);
    }

    /**
     * @param  list<string>  $confidences
     */
    private function weakestConfidence(array $confidences): string
    {
        if (in_array('UNKNOWN', $confidences, true)) {
            return 'UNKNOWN';
        }
        if (in_array('PROVISIONAL', $confidences, true)) {
            return 'PROVISIONAL';
        }

        return 'CONFIRMED';
    }
}
