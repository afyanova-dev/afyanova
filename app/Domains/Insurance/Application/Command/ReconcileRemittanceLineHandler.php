<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\AdjudicationRepository;
use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\Exception\RemittancePayerMismatch;
use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\RemittanceRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestrates `ReconcileRemittanceLine` (ADR-205).
 * Matches a remittance advice line to an adjudicated claim, evaluates underpayment/overpayment
 * discrepancies (§189/§190), transitions the claim to REMITTED, and records the settlement event.
 */
final class ReconcileRemittanceLineHandler
{
    public function __construct(
        private readonly RemittanceRepository $remittances,
        private readonly ClaimRepository $claims,
        private readonly AdjudicationRepository $adjudications,
    ) {}

    public function __invoke(ReconcileRemittanceLine $command): void
    {
        DB::transaction(function () use ($command): void {
            // 1. Lock remittance and claim for update
            $remittance = $this->remittances->getByIdForUpdate($command->remittanceId);
            $claim = $this->claims->getByIdForUpdate($command->claimId);

            // 2. Verify payer match (Invariant 11)
            if ((string) $claim->payer_id !== (string) $remittance->payer_id) {
                throw RemittancePayerMismatch::forClaimAndRemittance(
                    (string) $claim->id,
                    (string) $claim->payer_id,
                    (string) $remittance->payer_id,
                );
            }

            // 3. Verify facility match
            if ((string) $claim->facility_id !== (string) $remittance->facility_id) {
                throw new InvalidArgumentException(sprintf(
                    'Claim facility [%s] does not match remittance facility [%s].',
                    $claim->facility_id,
                    $remittance->facility_id,
                ));
            }

            // 4. Evaluate underpayment / overpayment discrepancy (§189, §190)
            $discrepancyReason = $command->discrepancyReason;
            $adjudication = $this->adjudications->getByClaimId($claim->id);
            if ($adjudication !== null && $discrepancyReason === null) {
                $comp = bccomp(Money::of($command->paidAmount), Money::of((string) $adjudication->total_approved_amount), 2);
                if ($comp < 0) {
                    $discrepancyReason = 'UNDERPAYMENT';
                } elseif ($comp > 0) {
                    $discrepancyReason = 'OVERPAYMENT';
                }
            }

            // 5. Reconcile line on remittance aggregate (emits RemittanceLineSettled)
            $now = CarbonImmutable::now();
            $remittance->reconcileLine([
                'remittanceLineId' => $command->remittanceLineId,
                'claimId' => (string) $claim->id,
                'invoiceId' => (string) $claim->invoice_id,
                'patientId' => (string) $claim->patient_id,
                'payerId' => (string) $claim->payer_id,
                'paidAmount' => $command->paidAmount,
                'contractualAdjustment' => $command->contractualAdjustment,
                'patientResponsibility' => $command->patientResponsibility,
                'deniedAmount' => $command->deniedAmount,
                'discrepancyReason' => $discrepancyReason,
                'settledByUserId' => $command->actorId,
            ], $now);

            // 6. Transition claim to REMITTED
            $claim->remit();

            $this->remittances->save($remittance);
            $this->claims->save($claim);
        });
    }
}
