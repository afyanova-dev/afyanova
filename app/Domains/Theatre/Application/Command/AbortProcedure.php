<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Abort a planned or in-progress surgical procedure (ADR-156 D-C). `$reason`
 * is stored ROW-ONLY on `complications` and carried on the audit row, never
 * on the event (ADR-136).
 */
final readonly class AbortProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public string $reason,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('AbortProcedure::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AbortProcedure::$actorId must be a UUID (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('AbortProcedure::$reason must not be blank.');
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
            action: 'theatre.surgical_procedure_aborted',
            actorId: $this->actorId,
            targetType: 'surgical_procedure',
            targetId: $this->procedureId,
            reason: trim($this->reason),
        );
    }
}
