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
 * Record a therapeutic prescribing decision (ADR-143 D-H / D-I / D-S).
 */
final readonly class RecordPrescribingDecision implements Auditable, AuthorizesAgainst
{
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $prescribingDecisionId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        public MedicationSubstance $medication,
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
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['prescribingDecisionId' => $prescribingDecisionId, 'actorId' => $actorId, 'patientId' => $patientId, 'encounterId' => $encounterId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordPrescribingDecision::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($clinicalPlanId !== null && ! Str::isUuid($clinicalPlanId)) {
            throw new InvalidArgumentException('RecordPrescribingDecision::$clinicalPlanId must be a UUID or null (IC §3).');
        }

        if ($indicationDiagnosisId !== null && ! Str::isUuid($indicationDiagnosisId)) {
            throw new InvalidArgumentException('RecordPrescribingDecision::$indicationDiagnosisId must be a UUID or null (IC §3).');
        }

        if ($indicationProblemId !== null && ! Str::isUuid($indicationProblemId)) {
            throw new InvalidArgumentException('RecordPrescribingDecision::$indicationProblemId must be a UUID or null (IC §3).');
        }

        // ADR-183 D-E: one input for the authorised quantity — the structured
        // pair is supplied INSTEAD OF the free-text quantity, never alongside it.
        if ($quantityValue !== null && $quantity !== null && trim($quantity) !== '') {
            throw new InvalidArgumentException(
                'RecordPrescribingDecision: $quantityValue and the free-text $quantity are mutually exclusive.'
            );
        }

        // ADR-183 D-B: quantity_value is a positive number; quantity_unit is
        // required iff quantity_value is present and absent otherwise.
        if ($quantityValue !== null) {
            if (! is_numeric($quantityValue) || (float) $quantityValue <= 0) {
                throw new InvalidArgumentException(
                    'RecordPrescribingDecision::$quantityValue must be a positive numeric string or null.'
                );
            }

            if ($quantityUnit === null || trim($quantityUnit) === '') {
                throw new InvalidArgumentException(
                    'RecordPrescribingDecision::$quantityUnit is required when $quantityValue is present.'
                );
            }
        } elseif ($quantityUnit !== null && trim($quantityUnit) !== '') {
            throw new InvalidArgumentException(
                'RecordPrescribingDecision::$quantityUnit must be absent when $quantityValue is null.'
            );
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordPrescribingDecision::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('RecordPrescribingDecision::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'RecordPrescribingDecision::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.prescribing_decisions.recorded',
            actorId: $this->actorId,
            targetType: 'prescribing_decision',
            targetId: $this->prescribingDecisionId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
