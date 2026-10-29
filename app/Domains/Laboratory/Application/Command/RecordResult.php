<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Record one laboratory result (a single measurable parameter — §13, §45–§48;
 * ADR-159 D-F). `$resultId` is client-minted (UUIDv7). MANUAL entry only in
 * Lab.3a — analyzer/import is Lab.6+.
 *
 * Exactly one of `valueNumeric` / `valueCode` / `valueText` carries the value,
 * matching `valueType` (§46–§48). `valueText` is free-text clinical content
 * (§48) — row-only, never event / audit (ADR-136). The reference range
 * (`referenceLow` / `referenceHigh` / `referenceText` / `referenceRangeSource`)
 * is the applicable range as known to the entering tech / analyzer — taken from
 * the catalog's `parameters[].reference_ranges` prefill (§51–§52) or supplied
 * directly — and is SNAPSHOT onto the result (a historical result never
 * re-interprets when the catalog changes). `abnormalFlag` is computed by the
 * handler from the numeric value vs the range.
 */
final readonly class RecordResult implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, a backdated result time must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 72;

    public function __construct(
        public string $resultId,
        public string $actorId,
        public string $specimenId,
        public string $orderItemId,
        public string $valueType,
        public ?float $valueNumeric = null,
        public ?string $valueCode = null,
        public ?string $valueText = null,
        public ?string $unit = null,
        public ?string $parameterCode = null,
        public ?string $parameterName = null,
        public ?float $referenceLow = null,
        public ?float $referenceHigh = null,
        public ?string $referenceText = null,
        public ?string $referenceRangeSource = null,
        public ?string $method = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['resultId' => $resultId, 'actorId' => $actorId, 'specimenId' => $specimenId, 'orderItemId' => $orderItemId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordResult::\${$name} must be a UUID (IC §3).");
            }
        }

        if (! in_array($valueType, [LabResult::VALUE_TYPE_NUMERIC, LabResult::VALUE_TYPE_CODED, LabResult::VALUE_TYPE_TEXT], true)) {
            throw new InvalidArgumentException('RecordResult::$valueType must be NUMERIC, CODED or TEXT.');
        }

        $present = array_filter([
            LabResult::VALUE_TYPE_NUMERIC => $valueNumeric !== null,
            LabResult::VALUE_TYPE_CODED => $valueCode !== null && trim($valueCode) !== '',
            LabResult::VALUE_TYPE_TEXT => $valueText !== null && trim($valueText) !== '',
        ]);

        if (array_keys($present) !== [$valueType]) {
            throw new InvalidArgumentException(sprintf(
                'RecordResult: exactly the %s value field must be set for valueType %s.',
                $valueType,
                $valueType,
            ));
        }

        if ($referenceLow !== null && $referenceHigh !== null && $referenceLow > $referenceHigh) {
            throw new InvalidArgumentException('RecordResult::$referenceLow must not exceed $referenceHigh.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordResult::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('RecordResult::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'RecordResult::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.result_entered',
            actorId: $this->actorId,
            targetType: 'lab_result',
            targetId: $this->resultId,
            metadata: [
                'specimen_id' => $this->specimenId,
                'order_item_id' => $this->orderItemId,
                'value_type' => $this->valueType,
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
