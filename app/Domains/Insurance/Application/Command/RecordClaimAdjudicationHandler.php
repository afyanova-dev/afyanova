<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\AdjudicationRepository;
use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\Exception\ClaimAlreadyAdjudicated;
use App\Domains\Insurance\Domain\Exception\IllegalClaimTransition;
use App\Domains\Insurance\Domain\Model\Adjudication;
use App\Domains\Insurance\Domain\Model\ClaimLine;
use App\Domains\Insurance\Domain\ValueObject\ClaimStatus;
use App\Domains\Insurance\Infrastructure\Sequence\AdjudicationNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestrates `RecordClaimAdjudication` (ADR-205).
 * Validates claim readiness for adjudication, ensures line matching, computes
 * totals, records the Adjudication aggregate, and updates the Claim status.
 */
final class RecordClaimAdjudicationHandler
{
    public function __construct(
        private readonly AdjudicationRepository $adjudications,
        private readonly ClaimRepository $claims,
        private readonly AdjudicationNumberSequence $sequence,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordClaimAdjudication $command): void
    {
        $tenantId = $this->context->tenantId();

        DB::transaction(function () use ($command, $tenantId): void {
            // 1. Lock claim for update
            $claim = $this->claims->getByIdForUpdate($command->claimId);

            // 1. Prevent duplicate terminal adjudication
            if ($this->adjudications->existsForClaim($claim->id)) {
                $existing = $this->adjudications->getByClaimId($claim->id);
                throw ClaimAlreadyAdjudicated::forClaim($claim->id, (string) $existing?->id);
            }

            // 2. Validate claim status
            if ($claim->status !== ClaimStatus::SUBMITTED && $claim->status !== ClaimStatus::PENDED) {
                throw IllegalClaimTransition::fromStatus('RecordClaimAdjudication', (string) $claim->status);
            }

            // 3. Verify claim lines
            $claimLines = $claim->lines->keyBy('id');
            foreach ($command->lines as $line) {
                if (! $claimLines->has($line['claimLineId'])) {
                    throw new InvalidArgumentException(sprintf(
                        'Claim line [%s] does not belong to claim [%s].',
                        $line['claimLineId'],
                        $claim->id,
                    ));
                }
            }

            // 4. Mint ADJ-%08d sequence
            $adjudicationNumber = $this->sequence->next($tenantId);
            $now = CarbonImmutable::now();

            // 5. Format lines for Adjudication aggregate
            $linesData = [];
            foreach ($command->lines as $line) {
                /** @var ClaimLine $claimLine */
                $claimLine = $claimLines->get($line['claimLineId']);
                $linesData[] = [
                    'claimLineId' => $line['claimLineId'],
                    'status' => $line['status'],
                    'claimedAmount' => (string) $claimLine->claimed_amount,
                    'approvedAmount' => $line['approvedAmount'],
                    'deniedAmount' => $line['deniedAmount'],
                    'patientResponsibility' => $line['patientResponsibility'],
                    'contractualAdjustment' => $line['contractualAdjustment'],
                    'denialReasonCode' => $line['denialReasonCode'] ?? null,
                    'remarks' => $line['remarks'] ?? null,
                ];
            }

            // 6. Record adjudication aggregate (validates arithmetic & records event)
            $adjudication = Adjudication::record([
                'id' => $command->adjudicationId,
                'tenantId' => $tenantId,
                'facilityId' => (string) $claim->facility_id,
                'adjudicationNumber' => $adjudicationNumber,
                'claimId' => (string) $claim->id,
                'invoiceId' => (string) $claim->invoice_id,
                'patientId' => (string) $claim->patient_id,
                'payerId' => (string) $claim->payer_id,
                'status' => $command->status,
                'adjudicationDate' => $command->adjudicationDate,
                'externalReference' => $command->externalReference,
                'notes' => $command->notes,
                'adjudicatedByUserId' => $command->actorId,
                'createdByUserId' => $command->actorId,
                'lines' => $linesData,
                'occurredAt' => $now,
                'recordedAt' => $now,
            ]);

            $this->adjudications->save($adjudication);

            // 7. Update Claim status
            $claim->adjudicate($command->status);
            $this->claims->save($claim);
        });
    }
}
