<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a laboratory quality-control run (14-laboratory-domain.md §83–§85;
 * ADR-163 D-F). `$qcRunId` is client-minted. The handler classifies PASS /
 * FAIL from `$observedValue` vs the expected range and, on FAIL, holds the
 * facility's VERIFIED-not-released results for `$testDefinitionId` from
 * release (§85, §159). `$analyzerRef` / `$controlMaterial` / `$controlLevel`
 * are open strings.
 */
final readonly class RecordQcRun implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $qcRunId,
        public string $actorId,
        public string $analyzerRef,
        public string $controlMaterial,
        public float $observedValue,
        public ?string $testDefinitionId = null,
        public ?string $controlLevel = null,
        public ?float $expectedLow = null,
        public ?float $expectedHigh = null,
        public ?string $unit = null,
        public ?string $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['qcRunId' => $qcRunId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordQcRun::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($testDefinitionId !== null && ! Str::isUuid($testDefinitionId)) {
            throw new InvalidArgumentException('RecordQcRun::$testDefinitionId must be a UUID or null (IC §3).');
        }

        if (trim($analyzerRef) === '' || trim($controlMaterial) === '') {
            throw new InvalidArgumentException('RecordQcRun::$analyzerRef and $controlMaterial must not be blank.');
        }

        if ($expectedLow !== null && $expectedHigh !== null && $expectedLow > $expectedHigh) {
            throw new InvalidArgumentException('RecordQcRun::$expectedLow must not exceed $expectedHigh.');
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
            action: 'laboratory.qc_run_recorded',
            actorId: $this->actorId,
            targetType: 'lab_qc_run',
            targetId: $this->qcRunId,
            metadata: ['analyzer_ref' => trim($this->analyzerRef)],
        );
    }
}
