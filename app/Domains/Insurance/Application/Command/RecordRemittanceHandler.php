<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Model\Remittance;
use App\Domains\Insurance\Domain\PayerReader;
use App\Domains\Insurance\Domain\RemittanceRepository;
use App\Domains\Insurance\Infrastructure\Sequence\RemittanceNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates `RecordRemittance` (ADR-205).
 */
final class RecordRemittanceHandler
{
    public function __construct(
        private readonly RemittanceRepository $remittances,
        private readonly PayerReader $payers,
        private readonly RemittanceNumberSequence $sequence,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordRemittance $command): void
    {
        $tenantId = $this->context->tenantId();

        // 1. Verify payer exists in tenant
        $this->payers->summary($command->payerId);

        DB::transaction(function () use ($command, $tenantId): void {
            $remittanceNumber = $this->sequence->next($tenantId);
            $now = CarbonImmutable::now();

            $lines = array_map(fn (array $l): array => [
                'claimId' => $l['claimId'] ?? null,
                'adjudicationId' => $l['adjudicationId'] ?? null,
                'externalClaimReference' => $l['externalClaimReference'] ?? null,
                'claimedAmount' => $l['claimedAmount'],
                'paidAmount' => $l['paidAmount'],
                'contractualAdjustment' => $l['contractualAdjustment'],
                'patientResponsibility' => $l['patientResponsibility'],
                'deniedAmount' => $l['deniedAmount'],
                'status' => $l['status'] ?? null,
                'discrepancyReason' => $l['discrepancyReason'] ?? null,
            ], $command->lines);

            $remittance = Remittance::record([
                'id' => $command->remittanceId,
                'tenantId' => $tenantId,
                'facilityId' => $command->facilityId,
                'remittanceNumber' => $remittanceNumber,
                'payerId' => $command->payerId,
                'paymentReference' => $command->paymentReference,
                'paymentMethod' => $command->paymentMethod,
                'remittanceDate' => $command->remittanceDate,
                'totalAmount' => $command->totalAmount,
                'notes' => $command->notes,
                'createdByUserId' => $command->actorId,
                'lines' => $lines,
                'occurredAt' => $now,
                'recordedAt' => $now,
            ]);

            $this->remittances->save($remittance);
        });
    }
}
