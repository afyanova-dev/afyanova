<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Start a planned surgical procedure (ADR-156 D-C). `$actualDescription` is
 * ROW-ONLY (ADR-136 deny-list).
 */
final readonly class StartProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public ?string $actualDescription = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('StartProcedure::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('StartProcedure::$actorId must be a UUID (IC §3).');
        }

        if ($actualDescription !== null && trim($actualDescription) === '') {
            throw new InvalidArgumentException('StartProcedure::$actualDescription must be a non-blank string or null.');
        }
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.surgical_procedure_started',
            actorId: $this->actorId,
            targetType: 'surgical_procedure',
            targetId: $this->procedureId,
        );
    }
}
