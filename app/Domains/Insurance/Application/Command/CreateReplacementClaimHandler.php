<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\AuthorizationReader;
use App\Domains\Insurance\Domain\ClaimLineRepository;
use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\Exception\AuthorizationNotFound;
use App\Domains\Insurance\Domain\Exception\ClaimAlreadySuperseded;
use App\Domains\Insurance\Domain\Exception\ClaimNotSubmitted;
use App\Domains\Insurance\Domain\Exception\NoClaimableLinesOnInvoice;
use App\Domains\Insurance\Domain\Model\Claim;
use App\Domains\Insurance\Domain\Model\ClaimLine;
use App\Domains\Insurance\Domain\ValueObject\ClaimStatus;
use App\Domains\Insurance\Infrastructure\Sequence\ClaimNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates `CreateReplacementClaim` (ADR-204; Invariant 7).
 * Atomically transitions superseded claim to SUPERSEDED, deactivates its lines,
 * and creates the replacement claim with active lines under row-lock and transaction.
 */
final class CreateReplacementClaimHandler
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ClaimLineRepository $lines,
        private readonly AuthorizationReader $authReader,
        private readonly ClaimNumberSequence $sequence,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateReplacementClaim $command): void
    {
        $tenantId = $this->context->tenantId();

        DB::transaction(function () use ($command, $tenantId): void {
            // 1. Lock original claim
            $original = $this->claims->getByIdForUpdate($command->supersedesClaimId);

            if ($original->isSuperseded() || $original->superseded_by_claim_id !== null) {
                throw ClaimAlreadySuperseded::forClaim($original->id, (string) $original->superseded_by_claim_id);
            }

            if (! $original->isSubmitted()) {
                throw ClaimNotSubmitted::forClaim($original->id, (string) $original->status);
            }

            // 2. Validate optional authorization
            $authId = $command->authorizationId ?? $original->authorization_id;
            if ($authId !== null) {
                try {
                    $auth = $this->authReader->summary($authId);
                } catch (AuthorizationNotFound) {
                    throw AuthorizationNotFound::id($authId);
                }
                if ($auth->memberCoverageId !== (string) $original->member_coverage_id) {
                    throw AuthorizationNotFound::id($authId);
                }
            }

            // 3. Load lines
            $originalLines = $this->lines->getLinesForClaim($original->id);
            if (empty($originalLines)) {
                throw NoClaimableLinesOnInvoice::forInvoice((string) $original->invoice_id);
            }

            // 4. Atomically deactivate original lines
            $this->lines->deactivateLinesForClaim($original->id);

            // 5. Mark original superseded
            $original->markSupersededBy($command->replacementClaimId);
            $this->claims->save($original);

            // 6. Mint sequence and create replacement claim
            $claimNumber = $this->sequence->next($tenantId);
            $now = CarbonImmutable::now();

            $replacement = new Claim([
                'id' => $command->replacementClaimId,
                'tenant_id' => $tenantId,
                'facility_id' => $original->facility_id,
                'claim_number' => $claimNumber,
                'invoice_id' => $original->invoice_id,
                'patient_id' => $original->patient_id,
                'payer_id' => $original->payer_id,
                'plan_id' => $original->plan_id,
                'member_coverage_id' => $original->member_coverage_id,
                'contract_id' => $original->contract_id,
                'authorization_id' => $authId,
                'status' => ClaimStatus::DRAFT,
                'total_claimed_amount' => $original->total_claimed_amount,
                'currency' => $original->currency,
                'primary_diagnosis_code' => $command->primaryDiagnosisCode ?? $original->primary_diagnosis_code,
                'notes' => $command->notes ?? $original->notes,
                'supersedes_claim_id' => $original->id,
                'created_by_user_id' => $command->actorId,
                'occurred_at' => $now,
                'recorded_at' => $now,
                'version' => 0,
            ]);

            try {
                $this->claims->save($replacement);
            } catch (UniqueConstraintViolationException $e) {
                throw ClaimAlreadySuperseded::forClaim($original->id, null, $e);
            }

            // 7. Insert replacement lines with line_active = true
            foreach ($originalLines as $origLine) {
                $newLine = new ClaimLine([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'claim_id' => $command->replacementClaimId,
                    'charge_id' => $origLine->charge_id,
                    'service_category' => $origLine->service_category,
                    'service_code' => $origLine->service_code,
                    'service_description' => $origLine->service_description,
                    'service_date' => $origLine->service_date,
                    'quantity' => $origLine->quantity,
                    'unit_price' => $origLine->unit_price,
                    'claimed_amount' => $origLine->claimed_amount,
                    'authorization_id' => $authId,
                    'diagnosis_code' => $command->primaryDiagnosisCode ?? $origLine->diagnosis_code,
                    'line_number' => $origLine->line_number,
                    'line_active' => true,
                    'occurred_at' => $now,
                    'recorded_at' => $now,
                    'version' => 0,
                ]);

                $this->lines->save($newLine);
            }
        });
    }
}
