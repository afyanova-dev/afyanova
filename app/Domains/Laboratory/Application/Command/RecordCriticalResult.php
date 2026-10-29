<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Flag a verified/released laboratory result as critical
 * (14-laboratory-domain.md §53–§54, §160; ADR-161 D-F). `$criticalResultId`
 * is client-minted (UUIDv7). `$severity` is open-coded (normalised uppercase
 * in the handler — §53). `$clinicalContext` is free text, row-only + audit,
 * never in the event.
 */
final readonly class RecordCriticalResult implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $criticalResultId,
        public string $actorId,
        public string $resultId,
        public string $severity,
        public ?string $clinicalContext = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['criticalResultId' => $criticalResultId, 'actorId' => $actorId, 'resultId' => $resultId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordCriticalResult::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($severity) === '') {
            throw new InvalidArgumentException('RecordCriticalResult::$severity must not be blank (§53).');
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
            action: 'laboratory.critical_result_identified',
            actorId: $this->actorId,
            targetType: 'lab_critical_result',
            targetId: $this->criticalResultId,
            reason: ($this->clinicalContext !== null && trim($this->clinicalContext) !== '') ? trim($this->clinicalContext) : null,
            metadata: ['result_id' => $this->resultId, 'severity' => strtoupper(trim($this->severity))],
        );
    }
}
