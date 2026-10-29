<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Complete an in-progress surgical procedure (ADR-156 D-C).
 * `$actualDescription` / `$findings` / `$complications` are ROW-ONLY
 * (ADR-136 deny-list); `$outcome` is coded and event-safe.
 */
final readonly class CompleteProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public ?string $actualDescription = null,
        public ?string $outcome = null,
        public ?string $findings = null,
        public ?string $complications = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('CompleteProcedure::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CompleteProcedure::$actorId must be a UUID (IC §3).');
        }

        if ($outcome !== null && trim($outcome) === '') {
            throw new InvalidArgumentException('CompleteProcedure::$outcome must be a non-blank string or null.');
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
            action: 'theatre.surgical_procedure_completed',
            actorId: $this->actorId,
            targetType: 'surgical_procedure',
            targetId: $this->procedureId,
        );
    }
}
