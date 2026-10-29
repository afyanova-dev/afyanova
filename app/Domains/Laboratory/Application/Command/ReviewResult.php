<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a review of an entered result (ENTERED → REVIEWED)
 * (14-laboratory-domain.md §57, §100; ADR-159 D-F). An optional intermediate
 * step — verification may go straight from ENTERED. No domain event.
 */
final readonly class ReviewResult implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $resultId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($resultId)) {
            throw new InvalidArgumentException('ReviewResult::$resultId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReviewResult::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'review';
    }

    public function subjectId(): string
    {
        return $this->resultId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.result_reviewed',
            actorId: $this->actorId,
            targetType: 'lab_result',
            targetId: $this->resultId,
        );
    }
}
