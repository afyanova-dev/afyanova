<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use App\Domains\Insurance\Domain\Exception\ClaimAlreadySuperseded;
use App\Domains\Insurance\Domain\Exception\ClaimNotDraft;
use App\Domains\Insurance\Domain\Exception\ClaimNotReadyForSubmission;
use App\Domains\Insurance\Domain\Exception\ClaimNotSubmitted;
use App\Domains\Insurance\Domain\Exception\IllegalClaimTransition;
use App\Domains\Insurance\Domain\Exception\ReplacementClaimCannotBeCancelled;
use App\Domains\Insurance\Domain\ValueObject\AdjudicationStatus;
use App\Domains\Insurance\Domain\ValueObject\ClaimReadinessStatus;
use App\Domains\Insurance\Domain\ValueObject\ClaimStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Third-Party Reimbursement Claim aggregate root (21-insurance-and-payer-domain.md
 * §76–§82; ADR-204).
 *
 * Captures and freezes the provider's billing claim submitted to a payer:
 *   - Minted `CLM-%08d` at creation (Sponsor Ruling D1, ADR-115).
 *   - Anchored immutably to an ISSUED insured Billing invoice (Sponsor Ruling D2, ADR-201).
 *   - Snapshots lines from Billing invoice lines (`claimed_amount` = `payer_responsibility`).
 *   - Invariant 7 (§272): one live claim line per charge in a tenant.
 *   - Invariant 8 (§272): SUBMITTED claims and lines are an immutable historical snapshot.
 *   - Atomic replacement handover via `CreateReplacementClaim` (single-successor invariant).
 *   - Zero ledger writes, zero writes to `bill_*` tables.
 *
 * @domain      Insurance
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $claim_number
 * @property string $invoice_id
 * @property string $patient_id
 * @property string $payer_id
 * @property string $plan_id
 * @property string $member_coverage_id
 * @property string|null $contract_id
 * @property string|null $authorization_id
 * @property string $status
 * @property string $total_claimed_amount
 * @property string $currency
 * @property string|null $readiness_status
 * @property CarbonImmutable|null $readiness_checked_at
 * @property array<string, mixed>|null $readiness_notes
 * @property string|null $primary_diagnosis_code
 * @property string|null $external_reference
 * @property string|null $notes
 * @property string|null $supersedes_claim_id
 * @property string|null $superseded_by_claim_id
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $submitted_by_user_id
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 * @property string $created_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Claim extends AggregateRoot
{
    protected $table = 'ins_claims';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'claim_number',
        'invoice_id',
        'patient_id',
        'payer_id',
        'plan_id',
        'member_coverage_id',
        'contract_id',
        'authorization_id',
        'status',
        'total_claimed_amount',
        'currency',
        'readiness_status',
        'readiness_checked_at',
        'readiness_notes',
        'primary_diagnosis_code',
        'external_reference',
        'notes',
        'supersedes_claim_id',
        'superseded_by_claim_id',
        'superseded_at',
        'submitted_at',
        'submitted_by_user_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'total_claimed_amount' => 'string',
            'readiness_checked_at' => 'immutable_datetime',
            'readiness_notes' => 'array',
            'superseded_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<ClaimLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ClaimLine::class, 'claim_id')->orderBy('line_number');
    }

    public function isDraft(): bool
    {
        return $this->status === ClaimStatus::DRAFT;
    }

    public function isReady(): bool
    {
        return $this->status === ClaimStatus::READY;
    }

    public function isSubmitted(): bool
    {
        return $this->status === ClaimStatus::SUBMITTED;
    }

    public function isAdjudicated(): bool
    {
        return $this->status === ClaimStatus::ADJUDICATED;
    }

    public function isRejected(): bool
    {
        return $this->status === ClaimStatus::REJECTED;
    }

    public function isPended(): bool
    {
        return $this->status === ClaimStatus::PENDED;
    }

    public function isRemitted(): bool
    {
        return $this->status === ClaimStatus::REMITTED;
    }

    public function isSuperseded(): bool
    {
        return $this->status === ClaimStatus::SUPERSEDED;
    }

    public function isCancelled(): bool
    {
        return $this->status === ClaimStatus::CANCELLED;
    }

    public function isReplacement(): bool
    {
        return $this->supersedes_claim_id !== null;
    }

    /**
     * Transition claim lifecycle upon payer adjudication (ADR-205).
     */
    public function adjudicate(string $adjudicationStatus): void
    {
        if ($this->status !== ClaimStatus::SUBMITTED && $this->status !== ClaimStatus::PENDED) {
            throw IllegalClaimTransition::fromStatus('RecordClaimAdjudication', $this->status);
        }

        if (in_array($adjudicationStatus, [AdjudicationStatus::APPROVED, AdjudicationStatus::PARTIALLY_APPROVED, AdjudicationStatus::DENIED], true)) {
            $this->status = ClaimStatus::ADJUDICATED;
        } elseif ($adjudicationStatus === AdjudicationStatus::REJECTED) {
            $this->status = ClaimStatus::REJECTED;
        } elseif ($adjudicationStatus === AdjudicationStatus::PENDED) {
            $this->status = ClaimStatus::PENDED;
        }

        $this->version++;
    }

    /**
     * Transition claim lifecycle upon remittance reconciliation (ADR-205).
     */
    public function remit(): void
    {
        if ($this->status !== ClaimStatus::ADJUDICATED && $this->status !== ClaimStatus::SUBMITTED) {
            throw IllegalClaimTransition::fromStatus('ReconcileRemittanceLine', $this->status);
        }

        $this->status = ClaimStatus::REMITTED;
        $this->version++;
    }

    /**
     * Update permitted draft metadata (ADR-204).
     */
    public function updateDraft(
        ?string $primaryDiagnosisCode,
        ?string $authorizationId,
        ?string $notes,
    ): void {
        if (! in_array($this->status, [ClaimStatus::DRAFT, ClaimStatus::READY], true)) {
            throw ClaimNotDraft::fromStatus('UpdateClaimDraft', $this->status);
        }

        if ($primaryDiagnosisCode !== null) {
            $this->primary_diagnosis_code = trim($primaryDiagnosisCode) === '' ? null : trim($primaryDiagnosisCode);
        }
        if ($authorizationId !== null) {
            $this->authorization_id = trim($authorizationId) === '' ? null : trim($authorizationId);
        }
        if ($notes !== null) {
            $this->notes = trim($notes) === '' ? null : trim($notes);
        }

        // Reset readiness to require re-validation after draft edits
        if ($this->status === ClaimStatus::READY) {
            $this->status = ClaimStatus::DRAFT;
            $this->readiness_status = null;
        }

        $this->version++;
    }

    /**
     * Apply readiness assessment result (§217/§218; ADR-204).
     *
     * @param  array<string, mixed>  $notes
     */
    public function setReadiness(string $readinessStatus, array $notes): void
    {
        if (! in_array($this->status, [ClaimStatus::DRAFT, ClaimStatus::READY], true)) {
            throw ClaimNotDraft::fromStatus('ValidateClaimReadiness', $this->status);
        }

        $readiness = ClaimReadinessStatus::assert($readinessStatus);
        $this->readiness_status = $readiness;
        $this->readiness_checked_at = CarbonImmutable::now();
        $this->readiness_notes = $notes;

        if ($readiness === ClaimReadinessStatus::READY) {
            $this->status = ClaimStatus::READY;
        } else {
            $this->status = ClaimStatus::DRAFT;
        }

        $this->version++;
    }

    /**
     * Formally submit the claim (ADR-204; Invariant 8).
     * Locks the claim into an immutable historical snapshot.
     */
    public function submit(string $actorId, ?string $externalReference): void
    {
        if ($this->status !== ClaimStatus::READY) {
            if ($this->status === ClaimStatus::DRAFT) {
                $rawReasons = is_array($this->readiness_notes['blocking_reasons'] ?? null)
                    ? $this->readiness_notes['blocking_reasons']
                    : ['Claim has not passed readiness validation'];
                $reasons = [];
                foreach ($rawReasons as $reason) {
                    if (is_string($reason)) {
                        $reasons[] = $reason;
                    }
                }
                if ($reasons === []) {
                    $reasons[] = 'Claim has not passed readiness validation';
                }

                throw ClaimNotReadyForSubmission::withReasons($this->id, $reasons);
            }
            throw IllegalClaimTransition::fromStatus('SubmitClaim', $this->status);
        }

        $this->status = ClaimStatus::SUBMITTED;
        $this->submitted_at = CarbonImmutable::now();
        $this->submitted_by_user_id = $actorId;
        $this->external_reference = $externalReference !== null ? trim($externalReference) : null;
        $this->version++;
    }

    /**
     * Cancel an unsubmitted claim (ADR-204; Invariant 7).
     * Releases the claimed charges.
     */
    public function cancel(string $actorId, string $reason): void
    {
        if (! in_array($this->status, ClaimStatus::CANCELLABLE, true)) {
            throw IllegalClaimTransition::fromStatus('CancelClaim', $this->status);
        }

        if ($this->supersedes_claim_id !== null) {
            throw ReplacementClaimCannotBeCancelled::forClaim($this->id);
        }

        $this->status = ClaimStatus::CANCELLED;
        $this->cancelled_at = CarbonImmutable::now();
        $this->cancelled_by_user_id = $actorId;
        $this->cancellation_reason = trim($reason);
        $this->version++;
    }

    /**
     * Retire the submitted claim in favor of an atomic replacement claim (ADR-204).
     */
    public function markSupersededBy(string $replacementClaimId): void
    {
        if ($this->status !== ClaimStatus::SUBMITTED) {
            throw ClaimNotSubmitted::forClaim($this->id, $this->status);
        }

        if ($this->superseded_by_claim_id !== null) {
            throw ClaimAlreadySuperseded::forClaim($this->id, $this->superseded_by_claim_id);
        }

        $this->status = ClaimStatus::SUPERSEDED;
        $this->superseded_at = CarbonImmutable::now();
        $this->superseded_by_claim_id = $replacementClaimId;
        $this->version++;
    }
}
