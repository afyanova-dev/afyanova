<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Correct a recorded PrescribingDecision by creating a superseding record (ADR-143 D-J / D-S).
 */
final readonly class CorrectPrescribingDecision implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $newPrescribingDecisionId,
        public string $supersedesPrescribingDecisionId,
        public string $actorId,
        public MedicationSubstance $medication,
        public string $reason,
        public ?string $dose = null,
        public ?string $route = null,
        public ?string $frequency = null,
        public ?string $duration = null,
        public ?string $quantity = null,
        public ?string $quantityValue = null,
        public ?string $quantityUnit = null,
        public ?string $dosingInstruction = null,
        public ?string $therapeuticIntent = null,
        public ?string $indication = null,
        public ?string $urgency = null,
        public ?string $clinicalPlanId = null,
        public ?string $indicationDiagnosisId = null,
        public ?string $indicationProblemId = null,
        public ?string $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($newPrescribingDecisionId)) {
            throw new InvalidArgumentException('CorrectPrescribingDecision::$newPrescribingDecisionId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($supersedesPrescribingDecisionId)) {
            throw new InvalidArgumentException('CorrectPrescribingDecision::$supersedesPrescribingDecisionId must be a UUID (IC §3).');
        }

        if ($newPrescribingDecisionId === $supersedesPrescribingDecisionId) {
            throw new InvalidArgumentException('CorrectPrescribingDecision: a prescribing decision cannot supersede itself.');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CorrectPrescribingDecision::$actorId must be a UUID (IC §3).');
        }

        if ($clinicalPlanId !== null && ! Str::isUuid($clinicalPlanId)) {
            throw new InvalidArgumentException('CorrectPrescribingDecision::$clinicalPlanId must be a UUID or null (IC §3).');
        }

        if ($indicationDiagnosisId !== null && ! Str::isUuid($indicationDiagnosisId)) {
            throw new InvalidArgumentException('CorrectPrescribingDecision::$indicationDiagnosisId must be a UUID or null (IC §3).');
        }

        if ($indicationProblemId !== null && ! Str::isUuid($indicationProblemId)) {
            throw new InvalidArgumentException('CorrectPrescribingDecision::$indicationProblemId must be a UUID or null (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('CorrectPrescribingDecision::$reason is required.');
        }

        // ADR-183 D-E: one input for the authorised quantity — the structured
        // pair is supplied INSTEAD OF the free-text quantity, never alongside it.
        if ($quantityValue !== null && $quantity !== null && trim($quantity) !== '') {
            throw new InvalidArgumentException(
                'CorrectPrescribingDecision: $quantityValue and the free-text $quantity are mutually exclusive.'
            );
        }

        // ADR-183 D-B: quantity_value is a positive number; quantity_unit is
        // required iff quantity_value is present and absent otherwise.
        if ($quantityValue !== null) {
            if (! is_numeric($quantityValue) || (float) $quantityValue <= 0) {
                throw new InvalidArgumentException(
                    'CorrectPrescribingDecision::$quantityValue must be a positive numeric string or null.'
                );
            }

            if ($quantityUnit === null || trim($quantityUnit) === '') {
                throw new InvalidArgumentException(
                    'CorrectPrescribingDecision::$quantityUnit is required when $quantityValue is present.'
                );
            }
        } elseif ($quantityUnit !== null && trim($quantityUnit) !== '') {
            throw new InvalidArgumentException(
                'CorrectPrescribingDecision::$quantityUnit must be absent when $quantityValue is null.'
            );
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CorrectPrescribingDecision::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('CorrectPrescribingDecision::$occurredAt must not be in the future.');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'correct';
    }

    public function subjectId(): string
    {
        return $this->supersedesPrescribingDecisionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.prescribing_decisions.corrected',
            actorId: $this->actorId,
            targetType: 'prescribing_decision',
            targetId: $this->newPrescribingDecisionId,
            reason: null,
        );
    }
}
