<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\Exception\ResultAmendmentIncomplete;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Correct a finalized laboratory result (14-laboratory-domain.md §61, §64,
 * §181; ADR-160 D-F). The corrected value replaces the current one on the
 * result row; the before/after + `$reason` are appended to
 * `lab_result_amendments` (§62 — no silent editing). `$reason` is free text,
 * row-only + on the audit row, never in the event (ADR-136).
 */
final readonly class AmendResult implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $resultId,
        public string $amendmentId,
        public string $actorId,
        public string $reason,
        public string $valueType,
        public ?float $valueNumeric = null,
        public ?string $valueCode = null,
        public ?string $valueText = null,
        public ?string $unit = null,
        public ?float $referenceLow = null,
        public ?float $referenceHigh = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['resultId' => $resultId, 'amendmentId' => $amendmentId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("AmendResult::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($reason) === '') {
            throw ResultAmendmentIncomplete::reasonRequired('AmendResult');
        }

        if (! in_array($valueType, [LabResult::VALUE_TYPE_NUMERIC, LabResult::VALUE_TYPE_CODED, LabResult::VALUE_TYPE_TEXT], true)) {
            throw new InvalidArgumentException('AmendResult::$valueType must be NUMERIC, CODED or TEXT.');
        }

        $present = array_keys(array_filter([
            LabResult::VALUE_TYPE_NUMERIC => $valueNumeric !== null,
            LabResult::VALUE_TYPE_CODED => $valueCode !== null && trim($valueCode) !== '',
            LabResult::VALUE_TYPE_TEXT => $valueText !== null && trim($valueText) !== '',
        ]));

        if ($present !== [$valueType]) {
            throw new InvalidArgumentException('AmendResult: exactly the value field matching $valueType must be set.');
        }

        if ($referenceLow !== null && $referenceHigh !== null && $referenceLow > $referenceHigh) {
            throw new InvalidArgumentException('AmendResult::$referenceLow must not exceed $referenceHigh.');
        }
    }

    public function policyAbility(): string
    {
        return 'amend';
    }

    public function subjectId(): string
    {
        return $this->resultId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.result_amended',
            actorId: $this->actorId,
            targetType: 'lab_result',
            targetId: $this->resultId,
            reason: trim($this->reason),
            metadata: ['amendment_id' => $this->amendmentId],
        );
    }
}
