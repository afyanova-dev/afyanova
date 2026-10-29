<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Release a verified laboratory result to authorized clinical users (VERIFIED →
 * RELEASED) (14-laboratory-domain.md §60, §102, §229; ADR-159 D-F). Passes
 * through `LabResultReleaseGate` — release is blocked on an unverified result
 * or an active release hold (Lab.5 QC).
 */
final readonly class ReleaseResult implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $resultId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($resultId)) {
            throw new InvalidArgumentException('ReleaseResult::$resultId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReleaseResult::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'release';
    }

    public function subjectId(): string
    {
        return $this->resultId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.result_released',
            actorId: $this->actorId,
            targetType: 'lab_result',
            targetId: $this->resultId,
        );
    }
}
