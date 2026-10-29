<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Start a procedure's execution — `{AUTHORIZED, READY} → IN_PROGRESS`
 * (13-clinical-procedures-domain.md §46–§48; ADR-146 D-F / D-H). Legal from
 * `READY` unconditionally, or from `AUTHORIZED` only when the definition does
 * NOT require a readiness check (the small-facility fast path).
 *
 * `$site` / `$laterality` are validated against the definition's
 * `requires_site` / `requires_laterality` flags — the performer knows the site
 * when about to act. `performed_by_user_id` is set to `$actorId` here (13 §19
 * — may differ from the requester / authorizer).
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::start` (`clinical.procedures.perform`
 * at the procedure's own facility) before the handler runs.
 */
final readonly class StartProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public ?string $site = null,
        public ?string $laterality = null,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('StartProcedure::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('StartProcedure::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'start';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.started',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
