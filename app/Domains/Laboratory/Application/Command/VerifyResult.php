<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Verify a laboratory result ({ENTERED|REVIEWED} → VERIFIED)
 * (14-laboratory-domain.md §57–§59, §157; ADR-159 D-F). Verification is a
 * distinct step from entry (Invariant 13) — the `laboratory.results.verify`
 * key. A same-user maker-checker block is a governance refinement, not
 * enforced in Lab.3a.
 */
final readonly class VerifyResult implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $resultId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($resultId)) {
            throw new InvalidArgumentException('VerifyResult::$resultId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('VerifyResult::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'verify';
    }

    public function subjectId(): string
    {
        return $this->resultId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.result_verified',
            actorId: $this->actorId,
            targetType: 'lab_result',
            targetId: $this->resultId,
        );
    }
}
